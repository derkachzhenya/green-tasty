<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnerGroupPreOrderRequest;
use App\Models\Reservation;
use App\Services\OwnerGroupPreOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OwnerGroupPreOrderController extends Controller
{
    public function show(Request $request, Reservation $reservation, OwnerGroupPreOrderService $service): JsonResponse
    {
        Gate::authorize('viewSummary', $reservation);

        return $this->respond($service->view($reservation, $request->user()));
    }

    public function quantity(OwnerGroupPreOrderRequest $request, Reservation $reservation, int $item, OwnerGroupPreOrderService $service): JsonResponse
    {
        return $this->respond($service->quantity($reservation, $request->user(), $item, $request->integer('quantity'), $request->version()));
    }

    public function removeGuest(OwnerGroupPreOrderRequest $request, Reservation $reservation, int $guest, OwnerGroupPreOrderService $service): JsonResponse
    {
        return $this->respond($service->removeGuest($reservation, $request->user(), $guest, $request->version()));
    }

    public function submit(OwnerGroupPreOrderRequest $request, Reservation $reservation, OwnerGroupPreOrderService $service): JsonResponse
    {
        return $this->respond($service->submit($reservation, $request->user(), $request->version()));
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data): JsonResponse
    {
        return response()->json($data)->header('ETag', $data['version'])->header('Cache-Control', 'no-store, private');
    }
}
