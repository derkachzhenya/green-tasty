<?php

namespace App\Http\Middleware;

use App\Services\GuestInvitationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGuestInvitation
{
    public function __construct(private GuestInvitationService $invitations) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Validation failures on this stateless JSON endpoint must never redirect into a session.
        $request->headers->set('Accept', 'application/json');
        $token = $request->route('token');

        if (! is_string($token) || $this->invitations->resolve($token) === null) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        return $next($request);
    }
}
