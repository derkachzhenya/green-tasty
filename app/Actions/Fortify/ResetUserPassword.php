<?php

namespace App\Actions\Fortify;

use App\Concerns\AuthPasswordRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use AuthPasswordRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->authPasswordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();
    }
}
