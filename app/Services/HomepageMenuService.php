<?php

namespace App\Services;

use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

class HomepageMenuService
{
    /**
     * Current public starting prices, never historical pre-order prices.
     *
     * @return array<string, string>
     */
    public function featuredPrices(): array
    {
        // One presentation catalog is shared with Vue; no fabricated prices or weights.
        $catalog = json_decode(File::get(resource_path('data/homepage.json')), true, flags: JSON_THROW_ON_ERROR);

        return MenuItem::query()
            ->select('name')->selectRaw('MIN(price) as price')
            ->whereIn('name', array_column($catalog['dishes'], 'name'))
            ->where('is_available', true)
            ->whereHas('category', fn (Builder $query) => $query->where('is_active', true)
                ->whereHas('restaurant', fn (Builder $query) => $query->where('is_active', true)))
            ->groupBy('name')->orderBy('name')->get()
            ->mapWithKeys(fn (MenuItem $dish): array => [$dish->name => $dish->price])->all();
    }
}
