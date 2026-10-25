<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\ReservationCardState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReservationFeedbackController extends Controller
{
    public function show(Reservation $reservation): JsonResponse
    {
        Gate::authorize('view', $reservation);
        $this->eligible($reservation);

        return response()->json(['feedback' => $reservation->feedback?->only(['restaurant_rating', 'service_rating', 'comment'])]);
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize('view', $reservation);
        $data = $request->validate([
            'restaurant_rating' => ['required', 'integer', 'between:1,5'],
            'service_rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($reservation, $data): JsonResponse {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('view', $locked);
            $this->eligible($locked);
            $feedback = $locked->feedback()->updateOrCreate([], $data);

            return response()->json(['feedback' => $feedback->only(['restaurant_rating', 'service_rating', 'comment'])]);
        }, attempts: 3);
    }

    private function eligible(Reservation $reservation): void
    {
        if (! in_array(ReservationCardState::phase($reservation), ['in_progress', 'completed'], true)) {
            throw new ConflictHttpException('Feedback is available during or after your visit.');
        }
    }
}
