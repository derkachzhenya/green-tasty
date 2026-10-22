<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitGroupPreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation
            && ($this->user()?->can('submitPreOrder', $reservation) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['_token', '_method']) as $field) {
                $validator->errors()->add($field, 'Submission does not accept order data.');
            }
        }];
    }
}
