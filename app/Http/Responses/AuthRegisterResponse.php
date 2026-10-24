<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\AuthCodeService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Laravel\Fortify\Contracts\RegisterResponse;
use Symfony\Component\HttpFoundation\Response;

class AuthRegisterResponse implements RegisterResponse
{
    public function toResponse($request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(StatefulGuard::class)->logout();
        $request->session()->regenerate();
        $codes = app(AuthCodeService::class);
        $context = $codes->context($request, AuthCodeService::REGISTRATION)
            ?? $codes->start($request, AuthCodeService::REGISTRATION, $user->email, $user);

        return $request->expectsJson() ? response()->json(['next' => 'verify', 'challenge' => $context], 201) : redirect()->route('auth.verify');
    }
}
