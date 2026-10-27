<?php

namespace Tests\Feature;

use App\Models\PreOrderItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\BookingRules;
use App\Services\RestaurantBookingService;
use Carbon\CarbonImmutable;
use Database\Seeders\GreenTastyDemoAddressSeeder;
use Database\Seeders\GreenTastyDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DemoRestaurantIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 08:00:00', 'UTC'));
        // Demo IDs deliberately differ from their Location 1–3 suffixes.
        Restaurant::create(['name' => 'Existing real restaurant', 'slug' => 'existing-before-demo', 'address' => 'Keep this address']);
        $this->seed(GreenTastyDemoSeeder::class);
    }

    public function test_alignment_preserves_identities_relationships_and_all_other_data_and_is_idempotent(): void
    {
        $restaurant = Restaurant::query()->where('slug', 'location-1')->sole();
        $reservation = $restaurant->reservations()->create([
            'restaurant_table_id' => $restaurant->tables()->firstOrFail()->id,
            'owner_user_id' => User::factory()->create()->id, 'code' => 'GT-EXISTING',
            'reserved_at' => now()->addDay(), 'guest_count' => 2,
        ]);
        $order = $reservation->preOrder()->create(['status' => 'draft']);
        $guest = $reservation->guests()->create(['name' => 'Existing guest']);
        $dish = $restaurant->menuCategories()->firstOrFail()->items()->firstOrFail();
        $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => 2, 'unit_price' => '1.23']);
        $unrelated = Restaurant::create(['name' => 'Unrelated', 'slug' => 'unrelated', 'address' => 'Preserve this real address']);
        $before = $this->snapshot();
        $identities = Restaurant::query()->get()->keyBy('id')->map(fn (Restaurant $row) => $row->only(['id', 'name', 'slug', 'phone', 'is_active']))->all();

        $this->align();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($identities, Restaurant::query()->get()->keyBy('id')->map(fn (Restaurant $row) => $row->only(['id', 'name', 'slug', 'phone', 'is_active']))->all());
        $this->assertSame('Preserve this real address', $unrelated->fresh()->address);
        $this->assertSame('1.23', PreOrderItem::query()->sole()->unit_price);
        foreach (self::locations() as [$slug, $address]) {
            $this->assertSame($address, Restaurant::query()->where('slug', $slug)->sole()->address);
        }

        $once = Restaurant::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->travel(1)->minutes();
        $this->align('local');
        $this->align('staging');
        $this->assertSame($once, Restaurant::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_alignment_is_disabled_by_default_and_cannot_run_in_production(string $environment, bool $enabled): void
    {
        $before = Restaurant::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $caught = null;
        try {
            $this->align($environment, $enabled);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught);
        $this->assertStringContainsString('local/demo/staging', $caught->getMessage());
        $this->assertSame($before, Restaurant::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public static function unsafeEnvironments(): array
    {
        return [['production', true], ['local', false], ['demo', false], ['staging', false]];
    }

    #[DataProvider('modifiedFixtures')]
    public function test_alignment_refuses_missing_or_modified_fixtures_atomically(array $changes): void
    {
        Restaurant::query()->where('slug', 'location-3')->update($changes);
        $before = Restaurant::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $caught = null;
        try {
            $this->align();
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught);
        $this->assertStringContainsString('No addresses were changed', $caught->getMessage());
        $this->assertSame($before, Restaurant::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public static function modifiedFixtures(): array
    {
        return [[['name' => 'A real operator’s restaurant']], [['address' => 'An independently edited address']], [['slug' => 'another-slug']]];
    }

    #[DataProvider('locations')]
    public function test_selected_restaurant_address_is_consistent_through_details_booking_persistence_and_summary(string $slug, string $address): void
    {
        $this->align();
        $owner = User::factory()->create();
        $restaurant = Restaurant::query()->where('slug', $slug)->sole();
        $table = $restaurant->tables()->where('is_active', true)->where('seats', '>=', 2)->firstOrFail();
        $this->get(route('restaurants.show', $restaurant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('restaurant.id', $restaurant->id)->where('restaurant.slug', $slug)->where('restaurant.address', $address));
        $this->actingAs($owner)->get(route('restaurants.bookings.create', $restaurant))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('restaurant.id', $restaurant->id)->where('restaurant.slug', $slug)->where('restaurant.address', $address));
        $this->getJson(route('restaurants.availability', [$restaurant, 'date' => '2026-10-25', 'time' => '12:00', 'guest_count' => 2]))
            ->assertOk()->assertJsonFragment(['id' => $table->id, 'name' => $table->name, 'seats' => $table->seats]);
        $this->post(route('restaurants.bookings.store', $restaurant), [
            'date' => '2026-10-25', 'time' => '12:00', 'guest_count' => 2, 'restaurant_table_id' => $table->id,
        ])->assertRedirect();
        $reservation = Reservation::query()->sole();
        $this->assertSame($restaurant->id, $reservation->restaurant_id);
        $this->assertSame($table->id, $reservation->restaurant_table_id);
        $this->assertSame($reservation->restaurant_id, $reservation->table->restaurant_id);
        foreach (['reservations.confirmation', 'reservations.show'] as $route) {
            $this->get(route($route, $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('reservation.restaurant.slug', $slug)->where('reservation.restaurant.address', $address));
        }
    }

    public static function locations(): array
    {
        return [['location-1', '48 Rustaveli Avenue'], ['location-2', '14 Baratashvili Street'], ['location-3', '9 Abashidze Street']];
    }

    public function test_foreign_tables_are_rejected_in_both_http_validation_and_direct_service_calls(): void
    {
        $owner = User::factory()->create();
        $restaurants = Restaurant::query()->whereIn('slug', array_column(self::locations(), 0))->orderBy('id')->get();
        $this->actingAs($owner);
        foreach ($restaurants as $restaurant) {
            $foreign = $restaurants->first(fn (Restaurant $candidate) => $candidate->id !== $restaurant->id)->tables()->firstOrFail();
            $this->postJson(route('restaurants.bookings.store', $restaurant), [
                'date' => '2026-10-25', 'time' => '12:00', 'guest_count' => 2, 'restaurant_table_id' => $foreign->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
            $caught = null;
            try {
                app(RestaurantBookingService::class)->book($restaurant, $owner, app(BookingRules::class)->start('2026-10-25', '12:00'), 2, $foreign->id);
            } catch (ValidationException $exception) {
                $caught = $exception;
            }
            $this->assertNotNull($caught);
            $this->assertArrayHasKey('restaurant_table_id', $caught->errors());
        }
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_guests', 0);
    }

    private function align(string $environment = 'demo', bool $enabled = true): void
    {
        $previous = app()->environment();
        app()->instance('env', $environment);
        config(['demo.align_restaurant_addresses' => $enabled]);
        try {
            // Exercise the seeder's own production guard, independently of Artisan's confirmation prompt.
            app(GreenTastyDemoAddressSeeder::class)->run();
        } finally {
            app()->instance('env', $previous);
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $rows = [];
        foreach (['users', 'restaurant_tables', 'menu_categories', 'menu_items', 'reservations', 'reservation_guests', 'pre_orders', 'pre_order_items'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }
}
