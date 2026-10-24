<?php

namespace App\Concerns;

use Illuminate\Validation\Rules\Password;

trait AuthPasswordRules
{
    /** @return list<string|Password> */
    protected function authPasswordRules(): array
    {
        $password = Password::min(8)->mixedCase()->numbers()->symbols();

        if (app()->isProduction()) {
            $password->uncompromised();
        }

        return ['required', 'string', 'max:16', $password, 'regex:/[\p{S}\p{P}]/u', 'confirmed'];
    }
}
