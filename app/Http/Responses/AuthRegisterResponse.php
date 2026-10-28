<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\RegisterResponse;
use Symfony\Component\HttpFoundation\Response;

class AuthRegisterResponse implements RegisterResponse
{
    public function toResponse($request): Response
    {
        // Fortify has already signed in the newly created account and rotated
        // its session. Use the normal destination, including a pending booking.
        $response = app(AuthLoginResponse::class)->toResponse($request);

        return $request->expectsJson() ? $response->setStatusCode(201) : $response;
    }
}
