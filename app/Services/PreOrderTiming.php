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
        $pending = in_array($reservation->status, ['draft', 'confirmed'], true)
            && $order?->status === 'draft' && $order->submitted_at === null
            && $reservation->guests()->whereNull('pre_order_submitted_at')->whereNull('invitation_revoked_at')
                ->whereHas('preOrderItems', fn ($q) => $q->where('pre_order_id', $order->id))->exists();
        if (in_array($reservation->status, ['draft', 'confirmed'], true) && $order !== null) {
            $activeGuests = $reservation->guests()->active()->pluck('id');
            if ($order->status === 'draft' && $order->submitted_at === null && Schema::hasTable('guest_pre_order_rounds')) {
                $pending = $pending || GuestPreOrderRound::query()->whereIn('reservation_guest_id', $reservation->guests()->active()->whereNull('invitation_revoked_at')->select('id'))->whereNull('submitted_at')->get(['items'])->contains(fn (GuestPreOrderRound $round): bool => $round->items !== []);
            }
            if ($order->status === 'submitted' && $order->submitted_at !== null) {
                $pending = ((int) $order->review_revision > 1 && (int) $order->review_revision !== (int) $order->reviewed_revision) || $order->items()->whereIn('reservation_guest_id', $activeGuests)->whereNull('superseded_at')
                    ->where(fn ($q) => $q->whereNull('review_published_at')->orWhere('restaurant_decision', 'rejected'))->exists();
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
