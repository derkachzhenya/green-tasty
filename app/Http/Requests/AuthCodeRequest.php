<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuthCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'purpose' => ['required', Rule::in(['registration', 'recovery'])],
            'code' => [$this->routeIs('auth.code.verify') ? 'required' : 'sometimes', 'string', 'max:128'],
        ];
    }
}
