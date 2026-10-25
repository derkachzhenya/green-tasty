<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use App\Services\BookingRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation && ($this->user()?->can('update', $reservation) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['time' => ['required', 'date_format:H:i'], 'guest_count' => ['required', 'integer', 'between:1,'.BookingRules::MAX_GUESTS],
            'duration_minutes' => ['required', 'integer', Rule::in([BookingRules::DURATION_MINUTES])]];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->all()) as $field) {
                if (! in_array($field, [...array_keys($this->rules()), '_token'], true)) {
                    $validator->errors()->add($field, 'This field is not allowed.');
                }
            }
        }];
    }
}
