<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Align existing, explicitly designated demo fixtures without recreating any records. */
class GreenTastyDemoAddressSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['demo', 'staging']) || config('demo.align_restaurant_addresses') !== true) {
            throw new RuntimeException('Demo address alignment requires a dedicated demo/staging environment and GREEN_TASTY_DEMO_ADDRESS_ALIGNMENT=true.');
        }

        /** @var array{locations: list<array{demoSlug: string, address: string}>} $catalog */
        $catalog = json_decode(File::get(resource_path('data/homepage.json')), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($catalog): void {
            $restaurants = Restaurant::query()->whereIn('slug', array_column($catalog['locations'], 'demoSlug'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('slug');

            // Validate the complete set before changing any row, including already-aligned runs.
            foreach ($catalog['locations'] as $index => $location) {
                $restaurant = $restaurants->get($location['demoSlug']);
                if ($restaurant === null || $restaurant->name !== 'Green Tasty - Location '.($index + 1)
                    || ! in_array($restaurant->address, ['Demo Address '.($index + 1), $location['address']], true)) {
                    throw new RuntimeException('Demo address alignment refused: missing or modified fixture '.$location['demoSlug'].'. No addresses were changed.');
                }
            }

            foreach ($catalog['locations'] as $location) {
                $restaurant = $restaurants->get($location['demoSlug']);
                if ($restaurant !== null && $restaurant->address !== $location['address']) {
                    $restaurant->address = $location['address'];
                    $restaurant->saveOrFail();
                }
            }
        }, attempts: 3);
    }
}
