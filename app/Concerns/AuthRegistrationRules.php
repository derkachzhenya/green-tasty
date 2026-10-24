<?php

namespace App\Concerns;

trait AuthRegistrationRules
{
    use AuthPasswordRules, ProfileValidationRules;

    /** @return array<string, mixed> */
    protected function registrationRules(?int $userId = null): array
    {
        $name = ['required', 'string', 'max:100', "regex:/\A[A-Za-z]+(?:['-][A-Za-z]+)*\z/"];

        return [
            'first_name' => $name, 'last_name' => $name,
            'email' => [...$this->emailRules($userId), 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]+$/'],
            'password' => $this->authPasswordRules(),
        ];
    }
}
