<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GuestDishSelectionRequest extends FormRequest
{
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
}
