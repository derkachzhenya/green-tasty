<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WaiterReservationService
{
    public function __construct(private RestaurantBookingService $bookings, private BookingRules $rules, private RestaurantPreOrderReviewService $reviews) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function dashboard(Restaurant $restaurant, User $staff, array $filters): array
    {
        Gate::forUser($staff)->authorize('staff', $restaurant);
        $date = $filters['date'] ?? CarbonImmutable::now(BookingRules::TIMEZONE)->format('Y-m-d');
        $start = CarbonImmutable::parse($date, BookingRules::TIMEZONE)->startOfDay()->utc();
        $query = $restaurant->reservations()->where('reserved_at', '>', $start->subMinutes(BookingRules::MAX_DURATION_MINUTES))->where('reserved_at', '<', $start->addDay())->endingAfter($start)
            ->with(['owner', 'preOrder', 'restaurant', 'table'])->withCount(['guests as active_participants' => fn ($q) => $q->whereNull('removed_at')]);
        if (! empty($filters['time'])) {
            $instant = CarbonImmutable::parse($date.' '.$filters['time'], BookingRules::TIMEZONE)->utc();
            $query->where('reserved_at', '<=', $instant)->endingAfter($instant);
        }
        if (! empty($filters['table'])) {
            $query->where('restaurant_table_id', $filters['table']);
        }
        $page = $query->orderBy('reserved_at')->orderBy('id')->paginate(24)->withQueryString();

        return ['restaurant' => $restaurant->only(['id', 'name', 'address']), 'filters' => [...$filters, 'date' => $date],
            'tables' => $restaurant->tables()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'seats']),
            'reservations' => $page->through(fn (Reservation $reservation): array => $this->card($reservation)),
            'notifications' => DB::table('reservation_events')->where('restaurant_id', $restaurant->id)->where('audience', 'restaurant')
                ->orderByDesc('id')->limit(10)->get(['reservation_id', 'kind', 'revision', 'created_at'])];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(Reservation $reservation): array
    {
        $order = $reservation->preOrder()->first();
        $phase = ReservationCardState::phase($reservation);
        $submitted = $order?->status === 'submitted' && $order->submitted_at !== null;

        return [...$this->bookings->confirmation($reservation), 'owner_name' => $reservation->owner?->name,
            'duration_minutes' => $reservation->duration_minutes,
            'table_id' => $reservation->restaurant_table_id, 'active_participants' => $reservation->guests()->active()->count(),
            'phase' => $phase, 'version' => $this->reviews->version($reservation),
            'pre_orders' => $reservation->guests()->active()->whereHas('preOrderItems', fn ($q) => $q->where('pre_order_id', $order?->id)->whereNull('superseded_at'))
                ->when(! $submitted, fn ($q) => $q->whereNotNull('pre_order_submitted_at'))->count(),
            'has_pre_order' => $submitted, 'needs_review' => $submitted && (int) $order->review_revision !== (int) $order->reviewed_revision,
            'updated' => $submitted && (int) $order->review_revision > (int) $order->reviewed_revision && (int) $order->review_revision > 1,
            'can_edit' => in_array($phase, ['upcoming', 'in_progress'], true) && $reservation->status === 'confirmed',
            'can_cancel' => $phase === 'upcoming' && $reservation->status === 'confirmed'];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(Restaurant $restaurant, Reservation $reservation, User $staff, string $version): array
    {
        return DB::transaction(function () use ($restaurant, $reservation, $staff, $version): array {
            Gate::forUser($staff)->authorize('staff', $restaurant);
            $locked = $restaurant->reservations()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                return $this->card($locked);
            }
            $this->reviews->match($locked, $version);
            if ($locked->status !== 'confirmed' || ReservationCardState::phase($locked) !== 'upcoming') {
                throw new ConflictHttpException('Only a future confirmed reservation can be cancelled.');
            }
            $locked->update(['status' => 'cancelled']);
            $this->reviews->event($locked, 'reservation_cancelled', 0, 'owner');

            return $this->card($locked);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Restaurant $restaurant, Reservation $reservation, User $staff, array $data, string $version): array
    {
        return DB::transaction(function () use ($restaurant, $reservation, $staff, $data, $version): array {
            Gate::forUser($staff)->authorize('staff', $restaurant);
            // Same restaurant -> table -> reservation lock order as customer bookings.
            $parent = Restaurant::query()->whereKey($restaurant->id)->sharedLock()->firstOrFail();
            $tables = $parent->tables()->whereIn('id', [$reservation->restaurant_table_id, $data['table_id']])->orderBy('id')->lockForUpdate()->get();
            $locked = $parent->reservations()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->reviews->match($locked, $version);
            $phase = ReservationCardState::phase($locked);
            if ($locked->status !== 'confirmed' || ! in_array($phase, ['upcoming', 'in_progress'], true)) {
                throw new ConflictHttpException('This reservation is read-only.');
            }
            $table = $tables->firstWhere('id', $data['table_id']);
            $this->rules->validateGuests((int) $data['guest_count']);
            if (! $table?->is_active || $table->seats < $data['guest_count'] || $data['guest_count'] < $locked->guests()->active()->count()) {
                throw ValidationException::withMessages(['table_id' => 'Keep every active participant within an available table capacity.']);
            }
            $start = CarbonImmutable::parse($data['date'].' '.$data['time'], BookingRules::TIMEZONE)->utc();
            $duration = (int) ($data['duration_minutes'] ?? $locked->duration_minutes);
            $this->rules->validateDuration($duration);
            if ($phase === 'in_progress') {
                if (! $start->equalTo($locked->reserved_at) || $duration !== $locked->duration_minutes) {
                    throw new ConflictHttpException('An in-progress reservation can change table or party size, but not its time interval.');
                }
            } else {
                $start = $this->rules->start($data['date'], $data['time']);
            }
            if ($this->bookings->overlapping(Reservation::query()->where('restaurant_table_id', $table->id)->whereKeyNot($locked->id), $start, $duration)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['table_id' => 'The table is occupied during this reservation.']);
            }
            $locked->update(['restaurant_table_id' => $table->id, 'reserved_at' => $start, 'guest_count' => $data['guest_count'], 'duration_minutes' => $duration]);

            return $this->card($locked);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Restaurant $restaurant, User $staff, array $data): array
    {
        Gate::forUser($staff)->authorize('staff', $restaurant);
        $owner = User::query()->where('email', $data['owner_email'])->first();
        if ($owner === null) {
            throw ValidationException::withMessages(['owner_email' => 'An existing customer account is required.']);
        }
        $start = $this->rules->start($data['date'], $data['time']);

        return $this->card($this->bookings->book($restaurant, $owner, $start, (int) $data['guest_count'], (int) $data['table_id'], (int) ($data['duration_minutes'] ?? BookingRules::DURATION_MINUTES)));
    }
}
