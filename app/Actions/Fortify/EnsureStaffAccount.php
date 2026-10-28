<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\LoginRateLimiter;

class EnsureStaffAccount
{
    public function __construct(private LoginRateLimiter $limiter) {}

    /** @param Closure(Request): mixed $next */
    public function handle(Request $request, Closure $next): mixed
    {
        // Check staff eligibility before Fortify creates an authenticated session.
        // Fortify still handles the actual sign-in, session rotation and throttling.
        return (new Timebox)->call(function () use ($request, $next): mixed {
            $user = User::where('email', $request->string('email')->toString())->first();
            if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password) || ! $user->hasStaffAccess()) {
                event(new Failed(config('fortify.guard', 'web'), null, $request->only('email', 'password')));
                $this->limiter->increment($request);
                throw ValidationException::withMessages(['email' => 'These credentials do not match an active staff account.']);
            }

            return $next($request);
        }, 200000);
    }
}
