<?php

namespace App\Http\Requests;

use App\Models\Restaurant;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends BookingAvailabilityRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $restaurant = $this->route('restaurant');

        return [
            ...parent::rules(),
            'restaurant_table_id' => [
                'required', 'integer', 'min:1',
                Rule::exists('restaurant_tables', 'id')->where('restaurant_id', $restaurant instanceof Restaurant ? $restaurant->id : null)->where('is_active', true),
            ],
        ];
    }
}
