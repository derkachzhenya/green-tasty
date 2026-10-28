<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffAccessController extends Controller
{
    public function login(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('staff.access');
        }

        // This form chooses its destination by role. Do not carry a protected
        // staff URL into the visitor form if the user switches back to it.
        $request->session()->forget('url.intended');

        return $this->loginPage();
    }

    public function show(Request $request): Response
    {
        if ($request->user() === null) {
            return $this->loginPage();
        }

        return Inertia::render('staff/Access', [
            'workplaces' => $request->user()->staffRestaurants()->wherePivot('is_active', true)->wherePivot('role', 'waiter')->where('restaurants.is_active', true)
                ->orderBy('restaurants.id')->get(['restaurants.id', 'restaurants.name', 'restaurants.address'])->map->only(['id', 'name', 'address'])->all(),
        ]);
    }

    public function enter(Request $request): RedirectResponse
    {
        $restaurant = $request->user()?->staffRestaurants()->wherePivot('is_active', true)->wherePivot('role', 'waiter')->where('restaurants.is_active', true)->orderBy('restaurants.id')->first();

        return $restaurant === null ? redirect()->route('staff.access') : redirect()->route('staff.reservations.index', ['restaurant' => $restaurant->id]);
    }

    private function loginPage(): Response
    {
        $enabled = (bool) config('staff.registration_enabled');
        $restaurant = $enabled ? Restaurant::query()->where('slug', config('staff.registration_restaurant'))->where('is_active', true)->first(['name', 'address']) : null;

        return Inertia::render('staff/Login', ['registration' => [
            'enabled' => $enabled,
            'restaurant' => $restaurant?->only(['name', 'address']),
        ]]);
    }
}
