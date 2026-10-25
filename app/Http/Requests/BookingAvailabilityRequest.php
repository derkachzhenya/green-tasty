<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Services\BookingRules;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class BookingAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restaurant = $this->route('restaurant');

        return $restaurant instanceof Restaurant
            && ($this->user()?->can('create', [Reservation::class, $restaurant]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'guest_count' => ['required', 'integer', 'between:1,'.BookingRules::MAX_GUESTS],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules()), ['_token']) as $field) {
                $validator->errors()->add($field, 'This field is not accepted for booking.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $this->bookingStart();
            } catch (ValidationException $exception) {
                $validator->errors()->merge($exception->errors());
            }
        }];
    }

    public function bookingStart(): CarbonImmutable
    {
        return app(BookingRules::class)->start($this->string('date')->toString(), $this->string('time')->toString());
    }
}
