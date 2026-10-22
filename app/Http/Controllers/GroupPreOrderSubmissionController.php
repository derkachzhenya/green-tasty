<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitGroupPreOrderRequest;
use App\Models\Reservation;
use App\Services\GroupPreOrderSubmissionService;
use Illuminate\Http\JsonResponse;

class GroupPreOrderSubmissionController extends Controller
{
    public function store(SubmitGroupPreOrderRequest $request, Reservation $reservation, GroupPreOrderSubmissionService $service): JsonResponse
    {
        return response()->json($service->submit($reservation, $request->user()))
            ->header('Cache-Control', 'no-store, private');
    }
}
