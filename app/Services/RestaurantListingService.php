<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;

class RestaurantListingService
{
    /**
     * @return list<array{id: int, name: string, slug: string, address: string, table_count: int}>
     */
    public function listActive(): array
    {
        return array_values(Restaurant::query()
            ->select(['id', 'name', 'slug', 'address'])
            ->where('is_active', true)
            ->withCount(['tables' => fn (Builder $query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Restaurant $restaurant): array => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'address' => $restaurant->address,
                'table_count' => (int) $restaurant->getAttribute('tables_count'),
            ])
            ->all());
    }
}
