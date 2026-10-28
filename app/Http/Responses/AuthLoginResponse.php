<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\AuthCodeService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

class AuthLoginResponse implements LoginResponse
{
    public function toResponse($request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if ($user->email_verified_at === null) {
            // Do not leave an authenticated session behind if delivery fails.
            app(StatefulGuard::class)->logout();
            $request->session()->regenerate();
            $codes = app(AuthCodeService::class);
            $existing = $codes->context($request, AuthCodeService::REGISTRATION);
            $context = $existing !== null && $existing['email'] === $user->email
                ? $existing : $codes->start($request, AuthCodeService::REGISTRATION, $user->email, $user);

            return $request->expectsJson() ? response()->json(['next' => 'verify', 'challenge' => $context]) : redirect()->route('auth.verify');
        }
        if ($user->isStaffAccount()) {
            $request->session()->forget('url.intended');
            $redirect = route('staff.entry');
        } else {
            $redirect = redirect()->intended(config('fortify.home'))->getTargetUrl();
            // Old reservation-detail links can survive logout in url.intended.
            // Sign-in should return visitors to the reservation cards, while
            // retaining a pending table search/booking destination.
            $path = parse_url($redirect, PHP_URL_PATH);
            if (is_string($path) && preg_match('#^/reservations/[^/]+(?:/confirmation)?/?$#', $path)) {
                $redirect = route('dashboard');
            }
        }
        $request->session()->put('password_hash_'.config('auth.defaults.guard'), $user->getAuthPassword());
        Inertia::flash('authNotification', 'You have signed in successfully.');

        return $request->expectsJson() ? response()->json(['next' => 'authenticated', 'redirect' => $redirect]) : redirect($redirect);
    }
}
