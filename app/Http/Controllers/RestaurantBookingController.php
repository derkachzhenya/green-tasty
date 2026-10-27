<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingAvailabilityRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Services\BookingRules;
use App\Services\RestaurantBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RestaurantBookingController extends Controller
{
    public function availability(BookingAvailabilityRequest $request, Restaurant $restaurant, RestaurantBookingService $bookings): JsonResponse
    {
        return response()->json(['tables' => $bookings->availableTables($restaurant, $request->bookingStart(), $request->integer('guest_count'), $request->integer('duration_minutes', BookingRules::DURATION_MINUTES))]);
    }

    public function store(StoreBookingRequest $request, Restaurant $restaurant, RestaurantBookingService $bookings): RedirectResponse
    {
        $reservation = $bookings->book($restaurant, $request->user(), $request->bookingStart(), $request->integer('guest_count'), $request->integer('restaurant_table_id'), $request->integer('duration_minutes', BookingRules::DURATION_MINUTES));

        return to_route('reservations.confirmation', $reservation);
    }

    public function show(Reservation $reservation, RestaurantBookingService $bookings): Response
    {
        Gate::authorize('view', $reservation);
        abort_unless(in_array($reservation->status, ['draft', 'confirmed', 'cancelled', 'completed'], true), 404);
        $confirmation = $bookings->confirmation($reservation);
        $preOrder = $reservation->preOrder;

        return Inertia::render('bookings/Confirmation', ['reservation' => $confirmation,
            'canEdit' => $reservation->status === 'confirmed' && $reservation->reserved_at->isFuture()
                && $reservation->restaurant?->is_active === true && $reservation->table?->is_active === true
                && ($preOrder === null || ($preOrder->status === 'draft' && $preOrder->submitted_at === null))]);
    }

    public function update(UpdateBookingRequest $request, Reservation $reservation, RestaurantBookingService $bookings): RedirectResponse
    {
        $bookings->update($reservation, $request->user(), $request->string('time')->toString(), $request->integer('guest_count'), $request->integer('duration_minutes'));

        return to_route('reservations.confirmation', $reservation);
    }
}
