<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvitationRequest;
use App\Http\Requests\StoreGuestInvitationRequest;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Services\GuestInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use SensitiveParameter;

class GuestInvitationController extends Controller
{
    public function store(StoreGuestInvitationRequest $request, Reservation $reservation, GuestInvitationService $service): JsonResponse
    {
        return response()->json($service->create(
            $reservation,
            $request->safe()->only(['name', 'email', 'phone']),
            $request->validated('expires_at')
        ), 201);
    }

    public function regenerate(InvitationRequest $request, Reservation $reservation, ReservationGuest $guest, GuestInvitationService $service): JsonResponse
    {
        return response()->json($service->regenerate($reservation, $guest, $request->validated('expires_at')));
    }

    public function destroy(Reservation $reservation, ReservationGuest $guest, GuestInvitationService $service): Response
    {
        Gate::authorize('manageInvitations', $reservation);
        $service->revoke($reservation, $guest);

        return response()->noContent();
    }

    public function show(#[SensitiveParameter] string $token, GuestInvitationService $service): JsonResponse
    {
        $summary = $service->view($token);

        return $summary === null
            ? response()->json(['message' => 'Invitation not found.'], 404)
            : response()->json($summary);
    }
}
