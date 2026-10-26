<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * @phpstan-type ReservationPage array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null, prev_page_url: string|null, next_page_url: string|null}
 */
class MyReservationsService
{
    public const PER_PAGE = 6;

    public function __construct(private RestaurantBookingService $bookings) {}

    /** @return ReservationPage */
    public function forOwner(User $owner, int $page = 1, string $filter = 'all'): array
    {
        Gate::forUser($owner)->authorize('viewAny', Reservation::class);

        $reservations = Reservation::query()
            // Ownership is always derived from authentication, never query parameters.
            ->where('owner_user_id', $owner->id)
            ->select(['id', 'restaurant_id', 'restaurant_table_id', 'owner_user_id', 'code', 'reserved_at', 'guest_count', 'status'])
            ->with(['restaurant:id,name,slug,address', 'table:id,name,seats', 'preOrder:id,reservation_id,status,submitted_at', 'feedback:id,reservation_id'])
            ->withCount(['guests as active_participants' => fn (Builder $q) => $q->whereNull('removed_at'),
                'guests as removed_participants' => fn (Builder $q) => $q->whereNotNull('removed_at'),
                'guests as submitted_pre_orders' => fn (Builder $q) => $q->whereNull('removed_at')
                    ->where(fn (Builder $guest) => $guest->whereNotNull('pre_order_submitted_at')->orWhereHas('reservation.preOrder', fn (Builder $order) => $order->where('status', 'submitted')->whereNotNull('submitted_at')))
                    ->whereHas('preOrderItems', fn (Builder $items) => $items->whereHas('preOrder', fn (Builder $order) => $order->whereColumn('pre_orders.reservation_id', 'reservation_guests.reservation_id')))])
            ->withExists(['guests as owner_submitted' => fn (Builder $q) => $q
                ->whereColumn('user_id', 'reservations.owner_user_id')->whereNotNull('pre_order_submitted_at')])
            ->when($filter !== 'all', fn (Builder $query) => $this->filter($query, $filter))
            ->orderByDesc('reserved_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, page: $page)
            ->withPath(route('dashboard'))
            ->appends($filter === 'all' ? [] : ['status' => $filter]);

        return [
            'data' => array_values($reservations->getCollection()->map(fn (Reservation $reservation): array => [
                ...$this->bookings->confirmation($reservation),
                'pre_order_status' => $reservation->preOrder?->status,
                'phase' => ReservationCardState::phase($reservation),
                'active_participants' => (int) $reservation->getAttribute('active_participants'),
                'removed_participants' => (int) $reservation->getAttribute('removed_participants'),
                'submitted_pre_orders' => (int) $reservation->getAttribute('submitted_pre_orders'),
                'can_invite' => $reservation->guest_count > 1 && ReservationCardState::acceptsPreOrder($reservation)
                    && ($reservation->preOrder === null || $reservation->preOrder->status === 'draft')
                    && (int) $reservation->getAttribute('submitted_pre_orders') < $reservation->guest_count,
                'can_add_pre_order' => ReservationCardState::acceptsPreOrder($reservation)
                    && ! $reservation->getAttribute('owner_submitted')
                    && ($reservation->preOrder === null || $reservation->preOrder->status === 'draft'),
                'can_view_group' => ReservationCardState::phase($reservation) === 'upcoming'
                    && ($reservation->getAttribute('owner_submitted') || (int) $reservation->getAttribute('submitted_pre_orders') > 0 || $reservation->preOrder?->status === 'submitted'),
                'feedback_action' => in_array(ReservationCardState::phase($reservation), ['in_progress', 'completed'], true)
                    ? ($reservation->feedback === null ? 'leave' : 'update') : null,
            ])->all()),
            'current_page' => $reservations->currentPage(),
            'last_page' => $reservations->lastPage(),
            'per_page' => $reservations->perPage(),
            'total' => $reservations->total(),
            'from' => $reservations->firstItem(),
            'to' => $reservations->lastItem(),
            'prev_page_url' => $reservations->previousPageUrl(),
            'next_page_url' => $reservations->nextPageUrl(),
        ];
    }

    /** @return list<array{id: int, code: string, minutes: int}> */
    public function warnings(User $owner): array
    {
        Gate::forUser($owner)->authorize('viewAny', Reservation::class);
        $now = CarbonImmutable::now();

        return array_values(Reservation::query()->where('owner_user_id', $owner->id)->whereIn('status', ['draft', 'confirmed'])
            ->where('reserved_at', '>', $now->addMinutes(30))->where('reserved_at', '<=', $now->addMinutes(45))
            ->orderBy('reserved_at')->get()->filter(fn (Reservation $r): bool => PreOrderTiming::state($r)['unconfirmed'])
            ->map(fn (Reservation $r): array => ['id' => $r->id, 'code' => $r->code,
                'minutes' => (int) ceil($now->diffInSeconds($r->reserved_at->toImmutable()->subMinutes(30), false) / 60)])->values()->all());
    }

    /** @return array<string, int> */
    public function counts(User $owner): array
    {
        Gate::forUser($owner)->authorize('viewAny', Reservation::class);
        $query = Reservation::query()->where('owner_user_id', $owner->id);
        $counts = ['all' => (clone $query)->count()];
        foreach (['upcoming', 'in_progress', 'completed', 'canceled'] as $phase) {
            $counts[$phase] = $this->filter(clone $query, $phase)->count();
        }

        return $counts;
    }

    /**
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    private function filter(Builder $query, string $filter): Builder
    {
        $now = CarbonImmutable::now();
        $endThreshold = $now->subMinutes(BookingRules::DURATION_MINUTES);

        return match ($filter) {
            'canceled' => $query->where('status', 'cancelled'),
            'completed' => $query->where(fn (Builder $q) => $q->where('status', 'completed')
                ->orWhere(fn (Builder $q) => $q->where('status', 'confirmed')->where('reserved_at', '<=', $endThreshold))),
            'in_progress' => $query->where('status', 'confirmed')->where('reserved_at', '<=', $now)->where('reserved_at', '>', $endThreshold),
            default => $query->whereNotIn('status', ['cancelled', 'completed'])->where(fn (Builder $q) => $q->where('status', '!=', 'confirmed')->orWhere('reserved_at', '>', $now)),
        };
    }
}
