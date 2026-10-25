<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class OwnerGroupPreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation && ($this->user()?->can('viewSummary', $reservation) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->isMethod('patch') ? ['quantity' => ['required', 'integer:strict', 'between:0,99']] : [];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = $this->isMethod('patch') ? ['quantity', '_token', '_method'] : ['_token', '_method'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        }];
    }

    public function version(): string
    {
        $value = $this->header('If-Match');
        if (! is_string($value) || preg_match('/\A"[a-f0-9]{64}"\z/', $value) !== 1) {
            throw ValidationException::withMessages(['version' => 'Refresh the group pre-order before continuing.']);
        }

        return $value;
    }
}
