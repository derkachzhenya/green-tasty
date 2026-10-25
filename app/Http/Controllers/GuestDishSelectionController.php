<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestDishSelectionRequest;
use App\Services\GuestDishSelectionService;
use Illuminate\Http\JsonResponse;
use SensitiveParameter;

class GuestDishSelectionController extends Controller
{
    public function show(#[SensitiveParameter] string $token, GuestDishSelectionService $service): JsonResponse
    {
        $summary = $service->view($token);

        return $summary === null
            ? response()->json(['message' => 'Invitation not found.'], 404)
            : response()->json($summary);
    }

    public function update(GuestDishSelectionRequest $request, #[SensitiveParameter] string $token, GuestDishSelectionService $service): JsonResponse
    {
        $summary = $service->replace($token, $request->selections(), $request->expectedVersion());

        return $summary === null
            ? response()->json(['message' => 'Invitation not found.'], 404)
            : response()->json($summary);
    }
}
