<?php

namespace App\Http\Controllers;

use App\Http\Requests\CorrectRejectedItemRequest;
use App\Models\Reservation;
use App\Services\RestaurantPreOrderReviewService;
use Illuminate\Http\JsonResponse;

class RejectedItemCorrectionController extends Controller
{
    public function __invoke(CorrectRejectedItemRequest $request, Reservation $reservation, int $item, RestaurantPreOrderReviewService $service): JsonResponse
    {
        return response()->json($service->correct($reservation, $request->user(), $item, $request->integer('menu_item_id'), $request->integer('quantity'), $request->version()));
    }
}
