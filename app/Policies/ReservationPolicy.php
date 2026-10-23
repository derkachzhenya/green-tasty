<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user, Restaurant $restaurant): bool
    {
        return $restaurant->is_active;
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->owner_user_id;
    }

    public function submitPreOrder(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->owner_user_id;
    }

    public function manageInvitations(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->owner_user_id;
    }

    public function viewSummary(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->owner_user_id;
    }
}
