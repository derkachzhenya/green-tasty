<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type PublicDish array{id: int, name: string, description: string|null, price: string}
 * @phpstan-type PublicCategory array{id: int, name: string, slug: string, dishes: list<PublicDish>}
 * @phpstan-type PublicRestaurant array{id: int, name: string, slug: string, address: string, phone: string|null, table_count: int}
 */
class RestaurantDetailsService
{
    /** @return PublicRestaurant */
    public function information(Restaurant $restaurant): array
    {
        $restaurant->loadCount(['tables' => fn (Builder $query) => $query->where('is_active', true)]);

        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'address' => $restaurant->address,
            'phone' => $restaurant->phone,
            'table_count' => (int) $restaurant->getAttribute('tables_count'),
        ];
    }

    /** @return list<PublicCategory> */
    public function menu(Restaurant $restaurant): array
    {
        $categories = $restaurant->menuCategories()
            ->select(['id', 'restaurant_id', 'name', 'slug'])
            ->where('is_active', true)
            ->whereHas('restaurant', fn (Builder $query) => $query->where('is_active', true))
            ->with(['items' => fn ($items) => $items
                ->select(['id', 'menu_category_id', 'name', 'description', 'price'])
                ->where('is_available', true)
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return array_values($categories->map(fn (MenuCategory $category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'dishes' => array_values($category->items->map(fn (MenuItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'price' => $item->price,
            ])->all()),
        ])->all());
    }
}
