<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class BookingSearchService
{
    public function __construct(private BookingRules $rules) {}

    /** @return list<array<string, mixed>> */
    public function search(?Restaurant $restaurant, string $date, string $time, int $guests, int $duration = BookingRules::DURATION_MINUTES): array
    {
        $this->rules->validateGuests($guests);
        $this->rules->validateDuration($duration);
        $day = CarbonImmutable::parse($date, BookingRules::TIMEZONE)->startOfDay();
        $starts = [];
        for ($minutes = 0; $minutes < 1440; $minutes += BookingRules::SLOT_MINUTES) {
            $start = $day->addMinutes($minutes);
            try {
                // Use creation's exact start rules, once per slot rather than once per table.
                $this->rules->validateStart($start);
                $allowed = true;
            } catch (ValidationException) {
                $allowed = false;
            }
            $starts[] = ['start' => $start, 'allowed' => $allowed];
        }
        // Eager-load restaurants and conflicts, rather than querying per slot or table.
        $tables = RestaurantTable::query()->where('is_active', true)->where('seats', '>=', $guests)
            ->whereHas('restaurant', fn (Builder $query) => $query->where('is_active', true))
            ->when($restaurant, fn (Builder $query) => $query->where('restaurant_id', $restaurant->id))
            ->with(['restaurant:id,name,slug,address', 'reservations' => fn ($query) => $query
                ->select(['id', 'restaurant_table_id', 'reserved_at', 'duration_minutes'])
                ->whereNotIn('status', ['cancelled', 'completed'])
                ->where('reserved_at', '>', $day->subMinutes(BookingRules::MAX_DURATION_MINUTES)->utc())
                ->where('reserved_at', '<', $day->addDay()->addMinutes($duration)->utc())])
            ->orderBy('restaurant_id')->orderBy('id')->get();

        return array_values($tables->map(function (RestaurantTable $table) use ($starts, $time, $duration): ?array {
            $slots = [];
            foreach ($starts as $candidate) {
                $start = $candidate['start'];
                $available = $candidate['allowed'] && ! $table->reservations->contains(fn (Reservation $reservation) => $reservation->endsAt()->greaterThan($start)
                    && $reservation->reserved_at->lessThan($start->addMinutes($duration)));
                $slots[] = ['time' => $start->format('H:i'), 'available' => $available];
            }
            if (! collect($slots)->contains(fn (array $slot) => $slot['available'] && ($time === 'all' || $slot['time'] === $time))) {
                return null;
            }

            return ['id' => $table->id, 'name' => $table->name, 'seats' => $table->seats,
                'restaurant' => $table->restaurant->only(['id', 'name', 'slug', 'address']), 'slots' => $slots];
        })->filter()->all());
    }
}
