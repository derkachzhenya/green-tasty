<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * @phpstan-type ReservationPage array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null, prev_page_url: string|null, next_page_url: string|null}
 */
class MyReservationsService
{
    public const PER_PAGE = 6;

    public function __construct(private RestaurantBookingService $bookings) {}

    /** @return ReservationPage */
    public function forOwner(User $owner, int $page = 1): array
    {
        Gate::forUser($owner)->authorize('viewAny', Reservation::class);

        $reservations = Reservation::query()
            // Ownership is always derived from authentication, never query parameters.
            ->where('owner_user_id', $owner->id)
            ->select(['id', 'restaurant_id', 'restaurant_table_id', 'code', 'reserved_at', 'guest_count', 'status'])
            ->with(['restaurant:id,name,slug,address', 'table:id,name,seats', 'preOrder:id,reservation_id,status'])
            ->orderByDesc('reserved_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, page: $page)
            ->withPath(route('dashboard'));

        return [
            'data' => array_values($reservations->getCollection()->map(fn (Reservation $reservation): array => [
                ...$this->bookings->confirmation($reservation),
                'pre_order_status' => $reservation->preOrder?->status,
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
}
