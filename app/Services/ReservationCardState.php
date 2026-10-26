<?php

namespace App\Services;

use App\Models\Reservation;
use Carbon\CarbonImmutable;

class ReservationCardState
{
    public static function phase(Reservation $reservation): string
    {
        if (in_array($reservation->status, ['cancelled', 'completed'], true)) {
            return $reservation->status === 'cancelled' ? 'canceled' : 'completed';
        }
        if ($reservation->status === 'confirmed') {
            $start = $reservation->reserved_at->toImmutable();
            $now = CarbonImmutable::now();
            if ($start->addMinutes(BookingRules::DURATION_MINUTES)->lessThanOrEqualTo($now)) {
                return 'completed';
            }
            if ($start->lessThanOrEqualTo($now)) {
                return 'in_progress';
            }
        }

        return 'upcoming';
    }

    public static function acceptsPreOrder(Reservation $reservation): bool
    {
        return ! PreOrderTiming::locked($reservation) && self::phase($reservation) === 'upcoming'
            && in_array($reservation->status, ['draft', 'confirmed'], true);
    }
}
