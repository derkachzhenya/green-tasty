<?php

namespace App\Services;

use App\Models\GuestPreOrderRound;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Server-time rules shared by every owner and guest pre-order entry point. */
class PreOrderTiming
{
    public static function locked(Reservation $reservation): bool
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($reservation->reserved_at->toImmutable()->subMinutes(30));
    }

    public static function ensureOpen(Reservation $reservation): void
    {
        if (self::locked($reservation)) {
            throw new ConflictHttpException('Pre-order changes close 30 minutes before the reservation.');
        }
    }

    /** @return array{server_now: string, warning_at: string, locks_at: string, locked: bool, unconfirmed: bool} */
    public static function state(Reservation $reservation): array
    {
        $order = $reservation->preOrder()->first();
        $pending = false;
        if (in_array($reservation->status, ['draft', 'confirmed'], true) && $order !== null) {
            $activeGuests = $reservation->guests()->active()->whereNull('invitation_revoked_at')->select('id');
            $pending = $order->items()->whereIn('reservation_guest_id', $activeGuests)->whereNull('superseded_at')
                ->where(fn ($query) => $query->whereNull('review_published_at')->orWhere('restaurant_decision', '!=', 'confirmed'))->exists();
            if (Schema::hasTable('guest_pre_order_rounds')) {
                $pending = $pending || GuestPreOrderRound::query()->whereIn('reservation_guest_id', $activeGuests)
                    ->whereNull('submitted_at')
                    ->when(Schema::hasColumn('guest_pre_order_rounds', 'cancelled_at'), fn ($query) => $query->whereNull('cancelled_at'))->get(['items'])
                    ->contains(fn (GuestPreOrderRound $round): bool => $round->items !== []);
            }
        }

        return [
            'server_now' => CarbonImmutable::now()->toIso8601String(),
            'warning_at' => $reservation->reserved_at->toImmutable()->subMinutes(45)->toIso8601String(),
            'locks_at' => $reservation->reserved_at->toImmutable()->subMinutes(30)->toIso8601String(),
            'locked' => self::locked($reservation), 'unconfirmed' => $pending,
        ];
    }
}
