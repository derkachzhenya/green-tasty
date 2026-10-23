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
        return Inertia::render('Dashboard', [
            'reservations' => Inertia::defer(
                fn () => $reservations->forOwner($request->user(), $request->integer('page', 1)),
                rescue: true,
            ),
        ]);
    }
}
