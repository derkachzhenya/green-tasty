<?php

namespace App\Http\Controllers;

use App\Http\Requests\WaiterReservationRequest;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Services\RestaurantPreOrderReviewService;
use App\Services\WaiterReservationService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class WaiterReservationController extends Controller
{
    public function index(WaiterReservationRequest $request, Restaurant $restaurant, WaiterReservationService $service): Response
    {
        return Inertia::render('staff/Reservations', $service->dashboard($restaurant, $request->user(), $request->validated()));
    }

    public function create(WaiterReservationRequest $request, Restaurant $restaurant, WaiterReservationService $service): JsonResponse
    {
        return response()->json($service->create($restaurant, $request->user(), $request->validated()), 201);
    }

    public function update(WaiterReservationRequest $request, Restaurant $restaurant, Reservation $reservation, WaiterReservationService $service): JsonResponse
    {
        return response()->json($service->update($restaurant, $reservation, $request->user(), $request->validated(), $request->version()));
    }

    public function cancel(WaiterReservationRequest $request, Restaurant $restaurant, Reservation $reservation, WaiterReservationService $service): JsonResponse
    {
        return response()->json($service->cancel($restaurant, $reservation, $request->user(), $request->version()));
    }

    public function review(WaiterReservationRequest $request, Restaurant $restaurant, Reservation $reservation, RestaurantPreOrderReviewService $service): JsonResponse
    {
        return response()->json($service->view($restaurant, $reservation, $request->user()));
    }

    public function decide(WaiterReservationRequest $request, Restaurant $restaurant, Reservation $reservation, int $item, RestaurantPreOrderReviewService $service): JsonResponse
    {
        return response()->json($service->decide($restaurant, $reservation, $request->user(), $item, $request->string('decision')->toString(), $request->version()));
    }

    public function submit(WaiterReservationRequest $request, Restaurant $restaurant, Reservation $reservation, RestaurantPreOrderReviewService $service): JsonResponse
    {
        return response()->json($service->submit($restaurant, $reservation, $request->user(), $request->version()));
    }
}
