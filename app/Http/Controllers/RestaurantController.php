<?php

namespace App\Http\Controllers;

use App\Services\RestaurantListingService;
use Inertia\Inertia;
use Inertia\Response;

class RestaurantController extends Controller
{
    public function index(RestaurantListingService $listing): Response
    {
        return Inertia::render('restaurants/Index', [
            'restaurants' => Inertia::defer(fn () => $listing->listActive(), rescue: true),
        ]);
    }
}
