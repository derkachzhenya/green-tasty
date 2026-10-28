<?php

namespace App\Http\Requests;

class StartGuestPreOrderRequest extends GuestDishSelectionRequest
{
    public function rules(): array
    {
        return ['items' => ['prohibited']];
    }
}
