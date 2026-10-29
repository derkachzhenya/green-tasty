<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestDishSelectionRequest;
use App\Models\Reservation;
use App\Services\GuestDishSelectionService;
use App\Services\GuestMenuService;
use App\Services\OwnerPreOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class OwnerPreOrderController extends Controller
{
    public function show(Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);

        return response()->view('guest', ['invalid' => false, 'ownerReservationId' => $reservation->id]);
    }

    public function menu(Request $request, Reservation $reservation, GuestMenuService $menus): JsonResponse
    {
        Gate::authorize('view', $reservation);

        return response()->json($menus->forOwner($reservation, $request->user()));
    }

    public function update(GuestDishSelectionRequest $request, Reservation $reservation, GuestDishSelectionService $selections): JsonResponse
    {
        Gate::authorize('view', $reservation);

        return response()->json($selections->replaceForOwner($reservation, $request->user(), $request->selections(), $request->expectedVersion()));
    }

    public function submit(GuestDishSelectionRequest $request, Reservation $reservation, OwnerPreOrderService $service): JsonResponse
    {
        Gate::authorize('view', $reservation);

        return response()->json($service->submit($reservation, $request->user(), $request->selections(), $request->expectedVersion()));
    }
}
