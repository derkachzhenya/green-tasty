<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OwnerReservationCancellation
{
    public static function version(Reservation $reservation): string
    {
        return '"'.hash('sha256', json_encode($reservation->only(['id', 'owner_user_id', 'status', 'reserved_at', 'duration_minutes', 'guest_count', 'restaurant_table_id', 'updated_at']), JSON_THROW_ON_ERROR)).'"';
    }

    public function cancel(Reservation $reservation, User $owner, string $version): void
    {
        DB::transaction(function () use ($reservation, $owner, $version): void {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($owner)->authorize('update', $locked);
            if ($locked->status === 'cancelled') {
                return;
            }
            if (! hash_equals(self::version($locked), $version) || ! ReservationCardState::acceptsPreOrder($locked)) {
                throw new ConflictHttpException('The reservation changed or cancellation is closed. Refresh Reservations.');
            }
            $locked->update(['status' => 'cancelled']);
            app(RestaurantPreOrderReviewService::class)->event($locked, 'reservation_cancelled', 0, 'restaurant');
        }, attempts: 3);
    }
}
