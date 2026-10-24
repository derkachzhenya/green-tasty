<?php

namespace App\Http\Requests;

use App\Concerns\AuthPasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class AuthRecoveryRequest extends FormRequest
{
    use AuthPasswordRules;

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->routeIs('password.email')
            ? ['email' => ['required', 'string', 'email', 'max:255', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]+$/']]
            : ['password' => $this->authPasswordRules()];
    }
}
