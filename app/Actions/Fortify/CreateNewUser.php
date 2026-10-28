<?php

namespace App\Actions\Fortify;

use App\Concerns\AuthRegistrationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use AuthRegistrationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Retain the existing full-name API while the approved UI uses two fields.
        if (! isset($input['first_name'], $input['last_name']) && isset($input['name'])) {
            [$input['first_name'], $input['last_name']] = array_pad(explode(' ', trim($input['name']), 2), 2, '');
        }
        Validator::make($input, $this->registrationRules())->validate();

        $user = new User([
            'name' => $input['first_name'].' '.$input['last_name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
        // Self-registration is approved immediately, without an email challenge.
        // Set readiness before Fortify dispatches Registered so no code is sent.
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
