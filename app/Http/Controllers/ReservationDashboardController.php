<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
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
            'canManageInvitations' => Gate::allows('manageInvitations', $reservation),
            'reservation' => fn () => $bookings->confirmation($reservation),
            'groupOrder' => Inertia::defer(fn () => $dashboard->groupOrder($reservation), rescue: true),
        ]);
    }
}
