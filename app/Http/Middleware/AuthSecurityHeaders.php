<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AuthSecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register.store')) {
            $key = 'registration:'.hash('sha256', (string) $request->ip());
            abort_if(RateLimiter::tooManyAttempts($key, 10), 429, 'Too many registration requests. Please try again later.');
            RateLimiter::hit($key, 60);
            // Fortify canonicalizes email before calling the registration action.
            // Reject malformed shapes first so arrays cannot reach Str::lower/trim.
            Validator::make($request->all(), ['email' => ['sometimes', 'string'], 'name' => ['sometimes', 'string']])->validate();
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
