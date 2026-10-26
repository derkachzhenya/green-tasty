<?php

namespace App\Http\Requests;

class CorrectRejectedItemRequest extends OwnerGroupPreOrderRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['menu_item_id' => ['required', 'integer:strict', 'min:1'], 'quantity' => ['required', 'integer:strict', 'between:1,99']];
    }

    public function after(): array
    {
        return [];
    }
}
