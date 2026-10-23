<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingAvailabilityRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Services\BookingRules;
use App\Services\RestaurantBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RestaurantBookingController extends Controller
{
    public function create(Restaurant $restaurant): Response
    {
        Gate::authorize('create', [Reservation::class, $restaurant]);
        $today = CarbonImmutable::now(BookingRules::TIMEZONE);

        return Inertia::render('bookings/Create', [
            'restaurant' => $restaurant->only(['id', 'name', 'slug', 'address']),
            'bookingRules' => [
                'timezone' => BookingRules::TIMEZONE, 'duration_minutes' => BookingRules::DURATION_MINUTES,
                'slot_minutes' => BookingRules::SLOT_MINUTES,
                'min_date' => $today->format('Y-m-d'), 'max_date' => $today->addDays(BookingRules::ADVANCE_DAYS)->format('Y-m-d'),
                'default_date' => $today->addDay()->format('Y-m-d'),
            ],
        ]);
    }

    public function availability(BookingAvailabilityRequest $request, Restaurant $restaurant, RestaurantBookingService $bookings): JsonResponse
    {
        return response()->json(['tables' => $bookings->availableTables($restaurant, $request->bookingStart(), $request->integer('guest_count'))]);
    }

    public function store(StoreBookingRequest $request, Restaurant $restaurant, RestaurantBookingService $bookings): RedirectResponse
    {
        $reservation = $bookings->book($restaurant, $request->user(), $request->bookingStart(), $request->integer('guest_count'), $request->integer('restaurant_table_id'));

        return to_route('reservations.confirmation', $reservation);
    }

    public function show(Reservation $reservation, RestaurantBookingService $bookings): Response
    {
        Gate::authorize('view', $reservation);
        abort_unless(in_array($reservation->status, ['draft', 'confirmed', 'cancelled', 'completed'], true), 404);

        return Inertia::render('bookings/Confirmation', ['reservation' => $bookings->confirmation($reservation)]);
    }
}
