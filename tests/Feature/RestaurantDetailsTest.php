<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantDetailsService;
use Database\Seeders\GreenTastyDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class RestaurantDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::create([
            'name' => 'Green Tasty Riverside', 'slug' => 'riverside',
            'address' => '48 Riverside Avenue', 'phone' => '+995 (555) 123-456',
        ]);
    }

    public function test_a_public_slug_route_returns_only_restaurant_information_and_defers_the_menu(): void
    {
        $this->restaurant->tables()->create(['name' => 'Active', 'seats' => 2]);
        $this->restaurant->tables()->create(['name' => 'Hidden', 'seats' => 4, 'is_active' => false]);

        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('restaurants/Show')
            ->where('auth.user', null)
            ->where('restaurant', [
                'id' => $this->restaurant->id, 'name' => 'Green Tasty Riverside',
                'slug' => 'riverside', 'address' => '48 Riverside Avenue',
                'phone' => '+995 (555) 123-456', 'table_count' => 1,
            ])
            ->missing('menu')
        );
        $this->assertSame('/restaurants/riverside', route('restaurants.show', $this->restaurant, false));
    }

    public function test_signed_in_users_can_browse_without_owning_a_reservation(): void
    {
        $this->actingAs(User::factory()->create())->get($this->url())
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('restaurants/Show'));
    }

    public function test_missing_slugs_and_numeric_ids_return_a_public_404_page(): void
    {
        foreach (['missing-location', (string) $this->restaurant->id] as $slug) {
            $this->get(route('restaurants.show', $slug))
                ->assertNotFound()->assertInertia(fn (Assert $page) => $page
                ->component('restaurants/NotFound')
                ->where('auth.user', null)
                ->missing('restaurant')->missing('menu')
                );
        }
    }

    public function test_inactive_restaurants_are_indistinguishable_from_missing_locations(): void
    {
        $this->restaurant->update(['is_active' => false]);

        $this->get($this->url())->assertNotFound()->assertInertia(fn (Assert $page) => $page
            ->component('restaurants/NotFound')->missing('restaurant')->missing('menu')
        );
        $this->getJson($this->url())->assertNotFound()->assertJsonMissingPath('props.restaurant');
    }

    public function test_only_active_categories_and_available_dishes_are_exposed(): void
    {
        $category = $this->category('Main courses');
        $dish = $category->items()->create([
            'name' => 'Vegetable Bowl', 'description' => 'Fresh seasonal vegetables.', 'price' => '12.50',
        ]);
        $category->items()->create(['name' => 'Unavailable dish', 'price' => '9.00', 'is_available' => false]);
        $hidden = $this->category('Hidden category', ['is_active' => false]);
        $hidden->items()->create(['name' => 'Hidden dish', 'price' => '99.00']);

        $this->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('menu', [[
                'id' => $category->id, 'name' => 'Main courses', 'slug' => 'main-courses',
                'dishes' => [[
                    'id' => $dish->id, 'name' => 'Vegetable Bowl',
                    'description' => 'Fresh seasonal vegetables.', 'price' => '12.50',
                ]],
            ]]))
        );
    }

    public function test_categories_and_dishes_follow_sort_order_with_id_as_a_stable_tiebreaker(): void
    {
        $later = $this->category('Later', ['sort_order' => 5]);
        $first = $this->category('First', ['sort_order' => 1]);
        $tie = $this->category('Tie', ['sort_order' => 1]);
        $lastDish = $first->items()->create(['name' => 'Last', 'price' => '1.00', 'sort_order' => 5]);
        $firstDish = $first->items()->create(['name' => 'First', 'price' => '2.00', 'sort_order' => 1]);
        $tieDish = $first->items()->create(['name' => 'Tie', 'price' => '3.00', 'sort_order' => 1]);

        $menu = app(RestaurantDetailsService::class)->menu($this->restaurant);

        $this->assertSame([$first->id, $tie->id, $later->id], array_column($menu, 'id'));
        $this->assertSame([$firstDish->id, $tieDish->id, $lastDish->id], array_column($menu[0]['dishes'], 'id'));
    }

    public function test_an_empty_menu_and_empty_active_categories_remain_valid(): void
    {
        $this->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('menu', []))
        );
        $empty = $this->category('Empty');
        $empty->items()->create(['name' => 'Sold out', 'price' => '8.00', 'is_available' => false]);
        $this->restaurant->update(['phone' => null]);

        $this->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->where('restaurant.phone', null)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('menu', 1)->where('menu.0.id', $empty->id)->where('menu.0.dishes', [])
            )
        );
    }

    public function test_nullable_descriptions_and_decimal_prices_are_preserved_without_float_conversion(): void
    {
        $category = $this->category('Prices');
        foreach (['0.10', '99999999.99'] as $price) {
            $category->items()->create(['name' => 'Price '.$price, 'price' => $price]);
        }

        $this->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('menu.0.dishes.0.description', null)
                ->where('menu.0.dishes.0.price', '0.10')
                ->where('menu.0.dishes.1.price', '99999999.99')
            )
        );
    }

    public function test_seeded_menus_are_scoped_to_the_selected_restaurant_even_with_shared_slugs_and_dish_names(): void
    {
        $this->seed(GreenTastyDemoSeeder::class);
        $location = Restaurant::query()->where('slug', 'location-1')->firstOrFail();
        $other = Restaurant::query()->where('slug', 'location-2')->firstOrFail();
        $other->menuCategories()->firstOrFail()->items()->create(['name' => 'Other location secret dish', 'price' => '18.00']);
        $ownIds = $location->menuCategories()->pluck('id')->all();
        $expectedDishIds = $location->menuCategories()->with('items')->get()->flatMap->items->pluck('id')->all();

        $this->get(route('restaurants.show', $location))->assertInertia(fn (Assert $page) => $page
            ->where('restaurant.id', $location->id)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('menu', 2)
                ->where('menu', function ($menu) use ($ownIds, $expectedDishIds): bool {
                    $this->assertEqualsCanonicalizing($ownIds, $menu->pluck('id')->all());
                    $this->assertEqualsCanonicalizing($expectedDishIds, $menu->pluck('dishes')->flatten(1)->pluck('id')->all());

                    return true;
                })
                ->missing('reservations')->missing('guests')->missing('pre_orders')
            )
        );
    }

    public function test_menu_query_count_stays_constant_as_categories_and_dishes_grow(): void
    {
        for ($index = 1; $index <= 10; $index++) {
            $category = $this->category('Category '.$index);
            $category->items()->create(['name' => 'Dish '.$index, 'price' => '10.00']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $details = app(RestaurantDetailsService::class);
            $details->information($this->restaurant);
            $menu = $details->menu($this->restaurant);

            $this->assertCount(10, $menu);
            $this->assertCount(3, DB::getQueryLog());
            foreach (DB::getQueryLog() as $query) {
                $this->assertStringStartsWith('select', strtolower($query['query']));
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_menu_errors_are_reported_without_private_details_and_retry_can_recover(): void
    {
        Exceptions::fake();
        $exception = new RuntimeException('Private SQL connection detail');
        $this->partialMock(RestaurantDetailsService::class, function (MockInterface $mock) use ($exception): void {
            $mock->shouldReceive('menu')->once()->andThrow($exception);
            $mock->shouldReceive('menu')->once()->andReturn([]);
        });
        $headers = $this->menuHeaders();

        $this->get($this->url(), $headers)->assertOk()
            ->assertJsonPath('rescuedProps', ['menu'])->assertJsonMissingPath('props.menu')
            ->assertDontSee('Private SQL connection detail');
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
        $this->get($this->url(), $headers)->assertOk()->assertJsonPath('props.menu', [])->assertJsonMissingPath('rescuedProps');
    }

    public function test_a_restaurant_closed_between_page_load_and_menu_request_returns_404(): void
    {
        $headers = $this->menuHeaders();
        $this->restaurant->update(['is_active' => false]);

        $this->get($this->url(), $headers)->assertNotFound()
            ->assertJsonPath('component', 'restaurants/NotFound')
            ->assertJsonMissingPath('props.restaurant')->assertJsonMissingPath('props.menu');
    }

    public function test_a_stale_restaurant_model_cannot_expose_a_now_inactive_menu(): void
    {
        $this->category('Main')->items()->create(['name' => 'Dish', 'price' => '10.00']);
        Restaurant::query()->whereKey($this->restaurant->id)->update(['is_active' => false]);

        $this->assertSame([], app(RestaurantDetailsService::class)->menu($this->restaurant));
    }

    private function category(string $name, array $attributes = []): MenuCategory
    {
        return $this->restaurant->menuCategories()->create(array_merge([
            'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)),
        ], $attributes));
    }

    private function url(): string
    {
        return route('restaurants.show', $this->restaurant);
    }

    private function menuHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->get($this->url())->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'restaurants/Show',
            'X-Inertia-Partial-Data' => 'menu',
        ];
    }
}
