<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingAvailabilityRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Services\BookingRules;
use App\Services\RestaurantBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(Request $request, Reservation $reservation, RestaurantBookingService $bookings): Response
    {
        Gate::authorize('view', $reservation);
        abort_unless(in_array($reservation->status, ['draft', 'confirmed', 'cancelled', 'completed'], true), 404);
        $confirmation = $bookings->confirmation($reservation);
        $preOrder = $reservation->preOrder;
        $canEdit = $reservation->status === 'confirmed' && $reservation->reserved_at->isFuture()
            && $reservation->restaurant?->is_active === true && $reservation->table?->is_active === true
            && ($preOrder === null || ($preOrder->status === 'draft' && $preOrder->submitted_at === null));
        $today = CarbonImmutable::now(BookingRules::TIMEZONE);

        return Inertia::render('bookings/Confirmation', [
            'reservation' => $confirmation,
            'canEdit' => $canEdit,
            'startEditing' => $canEdit && $request->boolean('edit'),
            'bookingBackground' => [
                'restaurant' => $reservation->restaurant?->is_active === true
                    ? $reservation->restaurant->only(['id', 'name', 'slug', 'address', 'phone'])
                    : null,
                'restaurants' => Restaurant::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'slug', 'address', 'phone']),
                'bookingRules' => [
                    'timezone' => BookingRules::TIMEZONE,
                    'duration_minutes' => BookingRules::DURATION_MINUTES,
                    'allowed_durations' => BookingRules::DURATIONS,
                    'slot_minutes' => BookingRules::SLOT_MINUTES,
                    'max_guests' => BookingRules::MAX_GUESTS,
                    'min_date' => $today->format('Y-m-d'),
                    'max_date' => $today->addDays(BookingRules::ADVANCE_DAYS)->format('Y-m-d'),
                    'default_date' => $today->addDay()->format('Y-m-d'),
                ],
                'initialSelection' => [
                    'restaurant' => $confirmation['restaurant']['slug'],
                    'date' => $confirmation['date'],
                    'time' => $confirmation['time'],
                    'guest_count' => $confirmation['guest_count'],
                    'duration_minutes' => $reservation->duration_minutes,
                ],
            ],
        ]);
    }

    public function update(UpdateBookingRequest $request, Reservation $reservation, RestaurantBookingService $bookings): RedirectResponse
    {
        $bookings->update($reservation, $request->user(), $request->string('time')->toString(), $request->integer('guest_count'), $request->integer('duration_minutes'));

        return to_route('reservations.confirmation', $reservation);
    }
}
