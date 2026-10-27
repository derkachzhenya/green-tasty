<?php

namespace App\Http\Controllers;

use App\Http\Requests\MyReservationsRequest;
use App\Services\MyReservationsService;
use Inertia\Inertia;
use Inertia\Response;

class MyReservationsController extends Controller
{
    public function __invoke(MyReservationsRequest $request, MyReservationsService $reservations): Response
    {
        $dashboardPage = $request->routeIs('account.dashboard');

        return Inertia::render('Dashboard', [
            'dashboardPage' => $dashboardPage,
            'preOrderWarnings' => fn () => $reservations->warnings($request->user()),
            'reservationCounts' => Inertia::defer(fn () => $reservations->counts($request->user()), rescue: true),
            'activeStatus' => $request->string('status', 'all')->toString(),
            'reservations' => Inertia::defer(
                fn () => $reservations->forOwner($request->user(), $request->integer('page', 1), $request->string('status', 'all')->toString(), $dashboardPage ? 'account.dashboard' : 'dashboard'),
                rescue: true,
            ),
        ]);
    }
}
