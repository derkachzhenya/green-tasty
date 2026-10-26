<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\User;

class RestaurantPolicy
{
    public function staff(User $user, Restaurant $restaurant): bool
    {
        return $restaurant->is_active && $user->staffRestaurants()->whereKey($restaurant->id)
            ->wherePivot('is_active', true)->wherePivot('role', 'waiter')->exists();
    }
}
