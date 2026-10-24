<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LockoutResponse;
use Laravel\Fortify\LoginRateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthLockoutResponse implements LockoutResponse
{
    public function toResponse($request): Response
    {
        $seconds = app(LoginRateLimiter::class)->availableIn($request);

        return response()->json([
            'message' => 'Your account is temporarily locked due to multiple failed login attempts. Please try again in 5 minutes.',
            'retry_after' => $seconds,
            'reason' => 'login-lockout',
        ], 429, ['Retry-After' => (string) $seconds, 'Cache-Control' => 'private, no-store']);
    }
}
