<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function manageInvitations(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->owner_user_id;
    }

    public function viewSummary(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->owner_user_id;
    }
}
