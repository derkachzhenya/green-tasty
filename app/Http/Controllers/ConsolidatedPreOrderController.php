<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\ConsolidatedPreOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ConsolidatedPreOrderController extends Controller
{
    public function show(Reservation $reservation, ConsolidatedPreOrderService $service): JsonResponse
    {
        Gate::authorize('viewSummary', $reservation);

        return response()->json($service->summarize($reservation));
    }
}
