<?php

namespace App\Http\Controllers;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StaffMenuController extends Controller
{
    public function __invoke(Restaurant $restaurant): Response
    {
        Gate::authorize('staff', $restaurant);
        $categories = $restaurant->menuCategories()->where('is_active', true)
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('id')->get();

        return Inertia::render('staff/Menu', [
            'restaurant' => $restaurant->only(['id', 'name', 'address']),
            'categories' => $categories->map(fn (MenuCategory $category): array => [
                'id' => $category->id, 'name' => $category->name,
                'dishes' => $category->items->map(fn (MenuItem $dish): array => [
                    'id' => $dish->id, 'name' => $dish->name, 'description' => $dish->description, 'price' => $dish->price,
                    'available' => $dish->is_available, 'sort_order' => $dish->sort_order,
                    'created_at' => $dish->created_at?->toIso8601String(), 'popularity' => 0,
                ])->all(),
            ])->all(),
        ]);
    }
}
