<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingSearchRequest;
use App\Models\Restaurant;
use App\Services\BookingRules;
use App\Services\BookingSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BookingSearchController extends Controller
{
    public function create(Request $request, ?Restaurant $restaurant = null): Response
    {
        abort_if($restaurant !== null && ! $restaurant->is_active, 403);
        $today = CarbonImmutable::now(BookingRules::TIMEZONE);

        return Inertia::render('bookings/Create', [
            'restaurant' => $restaurant?->only(['id', 'name', 'slug', 'address', 'phone']),
            'restaurants' => Restaurant::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'slug', 'address', 'phone']),
            // This is only a selection hint, revalidated by availability and again at persistence.
            'initialSelection' => $request->only(['restaurant', 'date', 'time', 'guest_count', 'table', 'duration_minutes']),
            'bookingRules' => [
                'timezone' => BookingRules::TIMEZONE, 'duration_minutes' => BookingRules::DURATION_MINUTES,
                'allowed_durations' => BookingRules::DURATIONS,
                'slot_minutes' => BookingRules::SLOT_MINUTES, 'max_guests' => BookingRules::MAX_GUESTS,
                'min_date' => $today->format('Y-m-d'), 'max_date' => $today->addDays(BookingRules::ADVANCE_DAYS)->format('Y-m-d'),
                'default_date' => $today->addDay()->format('Y-m-d'),
            ],
        ]);
    }

    public function availability(BookingSearchRequest $request, BookingSearchService $search): JsonResponse
    {
        return response()->json(['tables' => $search->search($request->restaurant(), $request->string('date')->toString(), $request->string('time')->toString(), $request->integer('guest_count'), $request->integer('duration_minutes', BookingRules::DURATION_MINUTES))]);
    }

    public function resume(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'restaurant' => ['required', 'string', 'exists:restaurants,slug'],
            'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'],
            'guest_count' => ['required', 'integer', 'between:1,'.BookingRules::MAX_GUESTS], 'table' => ['required', 'integer', 'min:1'],
            'duration_minutes' => ['sometimes', 'integer', Rule::in(BookingRules::DURATIONS)],
        ]);
        $restaurant = Restaurant::query()->where('slug', $data['restaurant'])->where('is_active', true)->firstOrFail();
        app(BookingRules::class)->start($data['date'], $data['time']);
        if (! $restaurant->tables()->whereKey($data['table'])->where('is_active', true)->where('seats', '>=', $data['guest_count'])->exists()) {
            throw ValidationException::withMessages(['restaurant_table_id' => 'This table is no longer available for your party. Search again.']);
        }
        // Construct a local destination ourselves: no redirect URL is accepted from the client.
        $destination = route('booking.create', $data);
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect($destination);
        }
        $request->session()->put('url.intended', $destination);

        return to_route($request->user() ? 'verification.notice' : 'login');
    }
}
