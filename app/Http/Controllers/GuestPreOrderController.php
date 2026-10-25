<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestNameRequest;
use App\Http\Requests\SubmitGuestPreOrderRequest;
use App\Services\GuestPreOrderService;
use Illuminate\Http\JsonResponse;
use SensitiveParameter;

class GuestPreOrderController extends Controller
{
    public function identify(GuestNameRequest $request, #[SensitiveParameter] string $token, GuestPreOrderService $service): JsonResponse
    {
        return $this->respond($service->identify($token, $request->validated('name')));
    }

    public function submit(SubmitGuestPreOrderRequest $request, #[SensitiveParameter] string $token, GuestPreOrderService $service): JsonResponse
    {
        return $this->respond($service->submit($token, $request->selections(), $request->expectedVersion()));
    }

    /** @param array<string, mixed>|null $data */
    private function respond(?array $data): JsonResponse
    {
        return $data === null
            ? response()->json(['message' => 'Invitation not found.'], 404)
            : response()->json($data);
    }
}
