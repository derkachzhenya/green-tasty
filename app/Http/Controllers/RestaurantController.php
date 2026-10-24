<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Services\HomepageMenuService;
use App\Services\RestaurantDetailsService;
use App\Services\RestaurantListingService;
use Inertia\Inertia;
use Inertia\Response;

class RestaurantController extends Controller
{
    public function index(RestaurantListingService $listing, HomepageMenuService $menu): Response
    {
        return Inertia::render('restaurants/Index', [
            'restaurants' => Inertia::defer(fn () => $listing->listActive(), rescue: true),
            'featuredPrices' => Inertia::defer(fn () => $menu->featuredPrices(), rescue: true),
        ]);
    }

    public function show(Restaurant $restaurant, RestaurantDetailsService $details): Response
    {
        abort_unless($restaurant->is_active, 404);

        return Inertia::render('restaurants/Show', [
            'restaurant' => fn () => $details->information($restaurant),
            'menu' => Inertia::defer(fn () => $details->menu($restaurant), rescue: true),
        ]);
    }
}
