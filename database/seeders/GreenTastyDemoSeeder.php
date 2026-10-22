<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use Illuminate\Database\Seeder;

class GreenTastyDemoSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            [
                'name' => 'Green Tasty - Location 1',
                'slug' => 'location-1',
                'address' => 'Demo Address 1',
            ],
            [
                'name' => 'Green Tasty - Location 2',
                'slug' => 'location-2',
                'address' => 'Demo Address 2',
            ],
            [
                'name' => 'Green Tasty - Location 3',
                'slug' => 'location-3',
                'address' => 'Demo Address 3',
            ],
        ];

        foreach ($locations as $location) {
            $restaurant = Restaurant::updateOrCreate(
                ['slug' => $location['slug']],
                [
                    'name' => $location['name'],
                    'address' => $location['address'],
                    'is_active' => true,
                ]
            );

            foreach ([2, 4, 6] as $index => $seats) {
                $restaurant->tables()->updateOrCreate(
                    ['name' => 'Table '.($index + 1)],
                    [
                        'seats' => $seats,
                        'is_active' => true,
                    ]
                );
            }

            $menu = [
                [
                    'slug' => 'starters',
                    'name' => 'Starters',
                    'item' => 'Garden Salad',
                    'price' => '8.50',
                ],
                [
                    'slug' => 'main-courses',
                    'name' => 'Main Courses',
                    'item' => 'Grilled Vegetables',
                    'price' => '14.00',
                ],
            ];

            foreach ($menu as $index => $entry) {
                $category = $restaurant->menuCategories()
                    ->updateOrCreate(
                        ['slug' => $entry['slug']],
                        [
                            'name' => $entry['name'],
                            'sort_order' => $index + 1,
                            'is_active' => true,
                        ]
                    );

                $category->items()->updateOrCreate(
                    ['name' => $entry['item']],
                    [
                        'price' => $entry['price'],
                        'is_available' => true,
                        'sort_order' => 1,
                    ]
                );
            }
        }
    }
}
