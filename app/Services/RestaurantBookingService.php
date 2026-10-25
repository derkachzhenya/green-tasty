<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RestaurantBookingService
{
    public function __construct(private BookingRules $rules) {}

    /** @return list<array{id: int, name: string, seats: int}> */
    public function availableTables(Restaurant $restaurant, CarbonImmutable $start, int $guests): array
    {
        $this->rules->validateGuests($guests);
        $this->rules->validateStart($start);

        return array_values($restaurant->tables()
            ->select(['id', 'name', 'seats'])
            ->where('is_active', true)->where('seats', '>=', $guests)
            ->whereHas('restaurant', fn (Builder $query) => $query->where('is_active', true))
            ->whereDoesntHave('reservations', fn (Builder $query) => $this->overlapping($query, $start))
            ->orderBy('seats')->orderBy('id')->get()
            ->map(fn (RestaurantTable $table): array => ['id' => $table->id, 'name' => $table->name, 'seats' => $table->seats])->all());
    }

    public function book(Restaurant $restaurant, User $owner, CarbonImmutable $start, int $guests, int $tableId): Reservation
    {
        $this->rules->validateGuests($guests);

        return DB::transaction(function () use ($restaurant, $owner, $start, $guests, $tableId): Reservation {
            // Shared parent lock stabilizes active status while allowing bookings at different tables.
            $lockedRestaurant = Restaurant::query()->whereKey($restaurant->id)->sharedLock()->firstOrFail();
            Gate::forUser($owner)->authorize('create', [Reservation::class, $lockedRestaurant]);
            $table = $lockedRestaurant->tables()->whereKey($tableId)->lockForUpdate()->first();

            if ($table === null || ! $table->is_active || $guests < 1 || $guests > $table->seats) {
                throw ValidationException::withMessages(['restaurant_table_id' => 'This table is not available for your party.']);
            }

            // The request may have waited for a competing transaction; validate against the current clock.
            $this->rules->validateStart($start);
            // A locking current read avoids stale repeatable-read snapshots after waiting for the table.
            $conflict = $this->overlapping(Reservation::query()->where('restaurant_table_id', $table->id), $start)->select('id')->lockForUpdate()->first();

            if ($conflict !== null) {
                throw ValidationException::withMessages(['restaurant_table_id' => 'This table was just booked. Please check availability again.']);
            }

            $reservation = $lockedRestaurant->reservations()->create([
                'restaurant_table_id' => $table->id,
                'owner_user_id' => $owner->id,
                'code' => 'GT-'.Str::upper((string) Str::ulid()),
                'reserved_at' => $start->utc(),
                'guest_count' => $guests,
                'status' => 'confirmed',
            ]);
            $reservation->guests()->create(['user_id' => $owner->id, 'name' => $owner->name]);

            return $reservation;
        }, attempts: 3);
    }

    public function update(Reservation $reservation, User $owner, string $time, int $guests): Reservation
    {
        $this->rules->validateGuests($guests);

        return DB::transaction(function () use ($reservation, $owner, $time, $guests): Reservation {
            // Same restaurant -> table lock order as creation. The reservation parent also
            // serializes editing with guest selections and group pre-order submission.
            $restaurant = Restaurant::query()->whereKey($reservation->restaurant_id)->sharedLock()->firstOrFail();
            $table = $restaurant->tables()->whereKey($reservation->restaurant_table_id)->lockForUpdate()->first();
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($owner)->authorize('update', $locked);
            $preOrder = $locked->preOrder()->lockForUpdate()->first();
            if (! $restaurant->is_active || ! $table?->is_active || $locked->status !== 'confirmed'
                || $locked->restaurant_id !== $restaurant->id || $locked->restaurant_table_id !== $table->id
                || $locked->reserved_at->lessThanOrEqualTo(CarbonImmutable::now())
                || ($preOrder !== null && ($preOrder->status !== 'draft' || $preOrder->submitted_at !== null))) {
                throw ValidationException::withMessages(['time' => 'This reservation cannot be changed online.']);
            }
            if ($guests < max(1, $locked->guests()->count()) || $guests > $table->seats) {
                throw ValidationException::withMessages(['guest_count' => 'Keep all registered participants within this table’s seating capacity.']);
            }
            $date = CarbonImmutable::instance($locked->reserved_at)->setTimezone(BookingRules::TIMEZONE)->format('Y-m-d');
            $start = $this->rules->start($date, $time);
            $conflict = $this->overlapping(Reservation::query()->where('restaurant_table_id', $table->id)->whereKeyNot($locked->id), $start)
                ->select('id')->lockForUpdate()->first();
            if ($conflict !== null) {
                throw ValidationException::withMessages(['time' => 'This table was just booked. Choose another time.']);
            }
            $locked->update(['reserved_at' => $start, 'guest_count' => $guests]);

            return $locked;
        }, attempts: 3);
    }

    /**
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    private function overlapping(Builder $query, CarbonImmutable $start): Builder
    {
        return $query->whereNotIn('status', ['cancelled', 'completed'])
            ->where('reserved_at', '>', $start->subMinutes(BookingRules::DURATION_MINUTES)->utc())
            ->where('reserved_at', '<', $start->addMinutes(BookingRules::DURATION_MINUTES)->utc());
    }

    /** @return array<string, mixed> */
    public function confirmation(Reservation $reservation): array
    {
        $reservation->loadMissing(['restaurant', 'table']);
        $start = CarbonImmutable::instance($reservation->reserved_at)->setTimezone(BookingRules::TIMEZONE);
        $end = $start->addMinutes(BookingRules::DURATION_MINUTES);

        return [
            'id' => $reservation->id, 'code' => $reservation->code, 'status' => $reservation->status,
            'guest_count' => $reservation->guest_count,
            'restaurant' => $reservation->restaurant?->only(['name', 'slug', 'address']),
            'table' => $reservation->table?->only(['name', 'seats']),
            'date' => $start->format('Y-m-d'), 'time' => $start->format('H:i'),
            'end_date' => $end->format('Y-m-d'), 'end_time' => $end->format('H:i'),
            'timezone' => BookingRules::TIMEZONE,
        ];
    }
}
