<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestDishSelectionRequest;
use App\Http\Requests\SubmitGuestPreOrderRequest;
use App\Models\Reservation;
use App\Services\GuestDishSelectionService;
use App\Services\GuestMenuService;
use App\Services\ReservationCardState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    public function submit(SubmitGuestPreOrderRequest $request, Reservation $reservation, GuestDishSelectionService $selections, GuestMenuService $menus): JsonResponse
    {
        Gate::authorize('view', $reservation);
        $version = $request->expectedVersion();

        return DB::transaction(function () use ($request, $reservation, $selections, $menus, $version): JsonResponse {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('view', $locked);
            $guest = $locked->guests()->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            if ($guest->pre_order_submitted_at === null) {
                if (! ReservationCardState::acceptsPreOrder($locked)) {
                    throw new ConflictHttpException('This reservation is no longer accepting pre-orders.');
                }
                $selections->replaceForOwner($locked, $request->user(), $request->selections(), $version);
                $guest->forceFill(['pre_order_submitted_at' => now()])->save();
            }

            return response()->json($menus->forOwner($locked, $request->user()));
        }, attempts: 3);
    }
}
