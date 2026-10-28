<?php

namespace App\Http\Controllers;

use App\Concerns\AuthRegistrationRules;
use App\Http\Responses\AuthLoginResponse;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StaffRegistrationController extends Controller
{
    use AuthRegistrationRules;

    public function __invoke(Request $request, StatefulGuard $guard): Response
    {
        abort_unless(config('staff.registration_enabled'), 403, 'Staff registration is currently unavailable.');
        $input = $request->all();
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower(trim($input['email']));
        }
        $data = Validator::make($input, [
            ...$this->registrationRules(),
        ])->validate();

        try {
            $user = DB::transaction(function () use ($data): User {
                $restaurant = Restaurant::query()->where('slug', config('staff.registration_restaurant'))->where('is_active', true)->lockForUpdate()->first();
                if ($restaurant === null) {
                    throw ValidationException::withMessages(['restaurant' => 'Staff registration is currently unavailable for this restaurant. Please contact the administrator.']);
                }

                // Open registration is enabled for the demonstration. The server fixes
                // the workplace and waiter role; the form cannot grant other access.
                $user = new User(['name' => $data['first_name'].' '.$data['last_name'], 'email' => $data['email'], 'password' => $data['password']]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->staffRestaurants()->attach($restaurant->id, ['role' => 'waiter', 'is_active' => true]);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists. Sign in or contact your restaurant administrator.']);
        }

        event(new Registered($user));
        $guard->login($user);
        $request->session()->regenerate();
        $response = app(AuthLoginResponse::class)->toResponse($request);
        if ($request->expectsJson()) {
            $response->setStatusCode(201);
        }

        return $response;
    }
}
