<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationGuest;
use Illuminate\Support\Facades\DB;

class PreOrderNotifications
{
    public static function guestSubmitted(ReservationGuest $guest): void
    {
        $reservation = $guest->reservation;
        if ($guest->user_id === $reservation->owner_user_id) {
            return;
        }
        DB::table('reservation_events')->insertOrIgnore([
            'reservation_id' => $reservation->id, 'restaurant_id' => $reservation->restaurant_id,
            'recipient_user_id' => $reservation->owner_user_id, 'audience' => 'owner',
            'kind' => 'guest_preorder_submitted', 'revision' => $guest->selection_version,
            'event_key' => hash('sha256', "guest:$guest->id:selection:$guest->selection_version"), 'created_at' => now(),
        ]);
    }

    public static function latest(Reservation $reservation): int
    {
        return (int) DB::table('reservation_events')->where('reservation_id', $reservation->id)
            ->where('audience', 'owner')->where('recipient_user_id', $reservation->owner_user_id)->max('id');
    }
}
