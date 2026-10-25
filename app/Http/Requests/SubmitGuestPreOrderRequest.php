<?php

namespace App\Http\Requests;

class SubmitGuestPreOrderRequest extends GuestDishSelectionRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [...parent::rules(), 'items' => ['required', 'array', 'list', 'min:1', 'max:100']];
    }
}
