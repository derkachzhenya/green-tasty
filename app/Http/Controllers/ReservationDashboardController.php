<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\ReservationCardState;
use App\Services\ReservationDashboardService;
use App\Services\RestaurantBookingService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReservationDashboardController extends Controller
{
    public function show(Reservation $reservation, RestaurantBookingService $bookings, ReservationDashboardService $dashboard): Response
    {
        Gate::authorize('view', $reservation);
        Gate::authorize('viewSummary', $reservation);

        return Inertia::render('reservations/Show', [
            'canEditOwnPreOrder' => ReservationCardState::acceptsPreOrder($reservation) && ($reservation->preOrder === null || $reservation->preOrder->status === 'draft'),
            'canSubmitPreOrder' => Gate::allows('submitPreOrder', $reservation) && ReservationCardState::acceptsPreOrder($reservation),
            'canManageInvitations' => Gate::allows('manageInvitations', $reservation) && ReservationCardState::acceptsPreOrder($reservation) && ($reservation->preOrder === null || $reservation->preOrder->status === 'draft'),
            'reservation' => fn () => $bookings->confirmation($reservation),
            'groupOrder' => Inertia::defer(fn () => $dashboard->groupOrder($reservation), rescue: true),
        ]);
    }
}
