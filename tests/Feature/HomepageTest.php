<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Services\HomepageMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_homepage_defers_real_menu_prices_without_exposing_model_fields(): void
    {
        $restaurant = Restaurant::create(['name' => 'Garden', 'slug' => 'garden', 'address' => '48 Rustaveli Avenue']);
        $this->dish($restaurant, 'Fresh Strawberry Mint Salad', '12.50');
        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('restaurants/Index')->where('auth.user', null)->missing('featuredPrices')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('featuredPrices', ['Fresh Strawberry Mint Salad' => '12.50'])
                ->has('restaurants', 1)->where('restaurants.0.slug', 'garden')));
    }

    public function test_starting_price_uses_only_current_available_dishes_at_active_categories_and_restaurants(): void
    {
        $first = Restaurant::create(['name' => 'First', 'slug' => 'first', 'address' => 'First address']);
        $second = Restaurant::create(['name' => 'Second', 'slug' => 'second', 'address' => 'Second address']);
        $inactive = Restaurant::create(['name' => 'Hidden', 'slug' => 'hidden', 'address' => 'Hidden address', 'is_active' => false]);
        $this->dish($first, 'Spring Salad', '12.50');
        $this->dish($second, 'Spring Salad', '10.05');
        $this->dish($inactive, 'Spring Salad', '0.01');
        $this->dish($first, 'Spring Salad', '0.02')->update(['is_available' => false]);
        $this->dish($first, 'Spring Salad', '0.03')->category->update(['is_active' => false]);
        $this->dish($first, 'Avocado Pine Nut Bowl', '99.99');
        $this->dish($first, 'An unrelated dish', '0.04');

        $this->assertSame([
            'Avocado Pine Nut Bowl' => '99.99', 'Spring Salad' => '10.05',
        ], app(HomepageMenuService::class)->featuredPrices());
    }

    public function test_empty_or_unavailable_menu_does_not_invent_prices_weights_or_currency(): void
    {
        $restaurant = Restaurant::create(['name' => 'Test', 'slug' => 'test', 'address' => 'Test address']);
        $this->dish($restaurant, 'Spring Salad', '17.00')->update(['is_available' => false]);
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('featuredPrices', [])));
    }

    public function test_public_pricing_is_one_read_only_query_and_does_not_change_existing_records(): void
    {
        $restaurant = Restaurant::create(['name' => 'Existing', 'slug' => 'location-1', 'address' => 'Demo Address 1']);
        $dish = $this->dish($restaurant, 'Spring Salad', '10.10');
        $originalRestaurant = $restaurant->fresh()->getRawOriginal();
        $originalDish = $dish->fresh()->getRawOriginal();
        DB::enableQueryLog();
        try {
            $this->assertSame(['Spring Salad' => '10.10'], app(HomepageMenuService::class)->featuredPrices());
            $queries = DB::getQueryLog();
            $this->assertCount(1, $queries);
            $this->assertStringStartsWith('select', strtolower($queries[0]['query']));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($originalRestaurant, $restaurant->fresh()->getRawOriginal());
        $this->assertSame($originalDish, $dish->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_price_loading_failure_is_sanitized_and_can_be_retried_independently(): void
    {
        Exceptions::fake();
        $exception = new RuntimeException('Private pricing connection details');
        $this->mock(HomepageMenuService::class, function (MockInterface $mock) use ($exception): void {
            $mock->shouldReceive('featuredPrices')->once()->andThrow($exception);
            $mock->shouldReceive('featuredPrices')->once()->andReturn([]);
        });
        $headers = [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->get(route('home'))->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'restaurants/Index',
            'X-Inertia-Partial-Data' => 'featuredPrices',
        ];
        $this->get(route('home'), $headers)->assertOk()->assertJsonPath('rescuedProps', ['featuredPrices'])
            ->assertJsonMissingPath('props.featuredPrices')->assertDontSee('Private pricing connection details');
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
        $this->get(route('home'), $headers)->assertOk()->assertJsonPath('props.featuredPrices', [])
            ->assertJsonMissingPath('rescuedProps');
    }

    private function dish(Restaurant $restaurant, string $name, string $price): MenuItem
    {
        $category = $restaurant->menuCategories()->create(['name' => 'Menu', 'slug' => 'menu-'.($restaurant->menuCategories()->count() + 1), 'is_active' => true]);

        return $category->items()->create(['name' => $name, 'price' => $price, 'is_available' => true]);
    }
}
