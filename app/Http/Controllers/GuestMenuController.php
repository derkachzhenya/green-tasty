<?php

namespace App\Http\Controllers;

use App\Services\GuestMenuService;
use Illuminate\Http\JsonResponse;
use SensitiveParameter;

class GuestMenuController extends Controller
{
    public function __invoke(#[SensitiveParameter] string $token, GuestMenuService $service): JsonResponse
    {
        $menu = $service->view($token);

        return $menu === null
            ? response()->json(['message' => 'Invitation not found.'], 404)
            : response()->json($menu);
    }
}
