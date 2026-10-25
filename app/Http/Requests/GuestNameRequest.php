<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuestNameRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255', 'not_regex:/[\p{Cc}\p{Cf}]/u']];
    }
}
