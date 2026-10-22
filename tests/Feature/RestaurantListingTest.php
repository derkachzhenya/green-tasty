<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantListingService;
use Database\Seeders\GreenTastyDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class RestaurantListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitors_can_open_the_listing_with_deferred_loading(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('restaurants/Index')
                ->where('auth.user', null)
                ->missing('restaurants')
            );
    }

    public function test_the_listing_returns_real_seeded_locations_and_only_public_fields(): void
    {
        $this->seed(GreenTastyDemoSeeder::class);
        $restaurant = Restaurant::query()->where('slug', 'location-1')->firstOrFail();

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('restaurants', 3)
                ->where('restaurants.0', [
                    'id' => $restaurant->id,
                    'name' => 'Green Tasty - Location 1',
                    'slug' => 'location-1',
                    'address' => 'Demo Address 1',
                    'table_count' => 3,
                ])
                ->where('restaurants.1.slug', 'location-2')
                ->where('restaurants.2.slug', 'location-3')
            )
        );
    }

    public function test_signed_in_users_can_also_open_the_public_listing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('restaurants/Index')
                ->where('auth.user.id', $user->id)
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('restaurants', []))
            );
    }

    public function test_an_empty_database_returns_an_empty_listing(): void
    {
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('restaurants', []))
        );
    }

    public function test_inactive_locations_are_hidden_and_only_active_tables_are_counted(): void
    {
        $this->seed(GreenTastyDemoSeeder::class);
        Restaurant::query()->where('slug', 'location-2')->update(['is_active' => false]);
        $restaurant = Restaurant::query()->where('slug', 'location-1')->firstOrFail();
        $restaurant->tables()->orderBy('id')->firstOrFail()->update(['is_active' => false]);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('restaurants', 2)
                ->where('restaurants.0.slug', 'location-1')
                ->where('restaurants.0.table_count', 2)
                ->where('restaurants.1.slug', 'location-3')
            )
        );
    }

    public function test_table_counts_are_loaded_in_one_query_and_browsing_does_not_write_data(): void
    {
        $this->seed(GreenTastyDemoSeeder::class);
        DB::enableQueryLog();

        try {
            $restaurants = app(RestaurantListingService::class)->listActive();

            $this->assertCount(3, $restaurants);
            $this->assertCount(1, DB::getQueryLog());
            $this->assertStringStartsWith('select', strtolower(DB::getQueryLog()[0]['query']));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_loading_failures_are_reported_without_exposing_details_and_can_be_retried(): void
    {
        Exceptions::fake();
        $exception = new RuntimeException('Private database connection details');
        $this->mock(RestaurantListingService::class, function (MockInterface $mock) use ($exception): void {
            $mock->shouldReceive('listActive')->once()->andThrow($exception);
            $mock->shouldReceive('listActive')->once()->andReturn([]);
        });
        $headers = [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->get(route('home'))->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'restaurants/Index',
            'X-Inertia-Partial-Data' => 'restaurants',
        ];

        $this->get(route('home'), $headers)
            ->assertOk()
            ->assertJsonPath('rescuedProps', ['restaurants'])
            ->assertJsonMissingPath('props.restaurants')
            ->assertDontSee('Private database connection details');
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);

        $this->get(route('home'), $headers)
            ->assertOk()
            ->assertJsonPath('props.restaurants', [])
            ->assertJsonMissingPath('rescuedProps');
    }
}
