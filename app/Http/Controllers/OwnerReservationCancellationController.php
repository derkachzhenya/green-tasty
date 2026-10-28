<?php

namespace App\Http\Controllers;

use App\Http\Requests\OwnerGroupPreOrderRequest;
use App\Models\Reservation;
use App\Services\OwnerReservationCancellation;
use Illuminate\Http\JsonResponse;

class OwnerReservationCancellationController extends Controller
{
    public function __invoke(OwnerGroupPreOrderRequest $request, Reservation $reservation, OwnerReservationCancellation $service): JsonResponse
    {
        $service->cancel($reservation, $request->user(), $request->version());

        return response()->json(['status' => 'cancelled'])->header('Cache-Control', 'no-store, private');
    }
}
