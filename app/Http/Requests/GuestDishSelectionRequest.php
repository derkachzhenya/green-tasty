<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class GuestDishSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return ! $reservation instanceof Reservation || ($this->user()?->can('view', $reservation) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'list', 'max:100'],
            'items.*' => ['required', 'array:menu_item_id,quantity'],
            'items.*.menu_item_id' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
            'items.*.quantity' => ['required', 'integer:strict', 'between:1,99'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['items']) as $field) {
                $validator->errors()->add($field, 'Only items may be submitted.');
            }
        }];
    }

    /** @return list<array{menu_item_id: int, quantity: int}> */
    public function selections(): array
    {
        $validated = $this->safe();
        $selections = [];

        foreach (array_keys($validated->array('items')) as $index) {
            $selections[] = [
                'menu_item_id' => $validated->integer('items.'.$index.'.menu_item_id'),
                'quantity' => $validated->integer('items.'.$index.'.quantity'),
            ];
        }

        return $selections;
    }

    public function expectedVersion(): int
    {
        $header = $this->header('If-Match');
        if (! is_string($header) || preg_match('/\A"(0|[1-9][0-9]{0,9})"\z/', $header, $matches) !== 1) {
            throw ValidationException::withMessages(['version' => 'Refresh your cart before saving.']);
        }

        return (int) $matches[1];
    }
}
