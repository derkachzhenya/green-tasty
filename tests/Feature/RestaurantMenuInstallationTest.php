<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\ConsolidatedPreOrderService;
use App\Services\GuestDishSelectionService;
use App\Services\GuestInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RestaurantMenuInstallationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('invalidCatalogs')]
    public function test_invalid_catalog_is_rejected_before_any_database_write(string $problem): void
    {
        $path = resource_path('data/restaurant-menu.json');
        $catalog = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        switch ($problem) {
            case 'negative_price': $catalog['dishes'][0]['price'] = '-1.00';
                break;
            case 'scientific_price': $catalog['dishes'][0]['price'] = '1e2';
                break;
            case 'string_availability': $catalog['dishes'][0]['available'] = 'false';
                break;
            case 'unknown_category': $catalog['dishes'][0]['category'] = 'missing';
                break;
            case 'duplicate_dish': $catalog['dishes'][] = $catalog['dishes'][0];
                break;
            case 'duplicate_category': $catalog['categories'][] = $catalog['categories'][0];
                break;
            case 'missing_image': $catalog['dishes'][0]['image'] = '/images/green-tasty/dishes/missing.png';
                break;
            case 'outside_dish_assets': $catalog['dishes'][0]['image'] = '/images/green-tasty/dishes/../logo.png';
                break;
        }
        $json = $problem === 'broken_json' ? '{' : json_encode($catalog, JSON_THROW_ON_ERROR);
        File::partialMock()->shouldReceive('get')->with($path)->andReturn($json);
        $this->artisan('menu:install', ['--apply' => true])->assertFailed();
        $this->assertDatabaseCount('restaurants', 0);
        $this->assertDatabaseCount('menu_categories', 0);
        $this->assertDatabaseCount('menu_items', 0);
    }

    public static function invalidCatalogs(): array
    {
        return array_combine($cases = ['negative_price', 'scientific_price', 'string_availability', 'unknown_category', 'duplicate_dish', 'duplicate_category', 'missing_image', 'outside_dish_assets', 'broken_json'], array_map(fn ($value) => [$value], $cases));
    }

    public function test_preview_never_writes_and_unknown_targets_fail_before_installation(): void
    {
        $this->artisan('menu:install')->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertDatabaseCount('restaurants', 0);
        $this->artisan('menu:install', ['--restaurant' => ['location-1', 'typo'], '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('restaurants', 0);
        $this->assertDatabaseCount('menu_items', 0);
    }

    public function test_clean_installation_is_complete_and_repeating_it_does_not_reset_edits(): void
    {
        $this->artisan('menu:install', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('restaurants', 3);
        $this->assertDatabaseCount('restaurant_tables', 9);
        $this->assertDatabaseCount('menu_categories', 9);
        $this->assertDatabaseCount('menu_items', 24);
        $dish = MenuItem::where('name', 'Spring Salad')->orderBy('id')->firstOrFail();
        $dish->update(['price' => '23.45', 'is_available' => false]);
        $before = $dish->fresh()->getRawOriginal();
        $this->artisan('menu:install', ['--apply' => true])->expectsOutputToContain('Created dishes: 0')->assertSuccessful();
        $this->assertSame($before, $dish->fresh()->getRawOriginal());
        $this->assertDatabaseCount('menu_items', 24);
        foreach (Restaurant::all() as $restaurant) {
            $this->assertSame(8, $restaurant->menuCategories()->where('slug', 'appetizers')->sole()->items()->count());
        }
        $asparagus = MenuItem::where('name', 'Green Asparagus Salad')->firstOrFail();
        $this->assertFalse($asparagus->is_available);
    }

    public function test_installation_preserves_existing_rows_and_orders_and_the_live_guest_api_sees_new_dishes(): void
    {
        $restaurant = Restaurant::create(['name' => 'Existing restaurant', 'slug' => 'location-1', 'address' => 'Keep address']);
        $table = $restaurant->tables()->create(['name' => 'Existing table', 'seats' => 4]);
        $category = $restaurant->menuCategories()->create(['name' => 'Starters', 'slug' => 'starters', 'sort_order' => 1]);
        $dish = $category->items()->create(['name' => 'Garden Salad', 'price' => '8.50']);
        $reservation = Reservation::create(['owner_user_id' => User::factory()->create()->id, 'restaurant_id' => $restaurant->id, 'restaurant_table_id' => $table->id, 'guest_count' => 4, 'code' => 'MENU-INSTALL', 'reserved_at' => '2026-11-01 08:00:00', 'status' => 'confirmed']);
        $issued = app(GuestInvitationService::class)->create($reservation, ['name' => 'Alice']);
        $token = basename(parse_url($issued['invitation_url'], PHP_URL_PATH));
        app(GuestDishSelectionService::class)->replace($token, [['menu_item_id' => $dish->id, 'quantity' => 2]], 0);
        $protected = [$restaurant, $table, $category, $dish, $reservation, $reservation->guests()->sole(), $reservation->preOrder()->sole(), $reservation->preOrder()->sole()->items()->sole()];
        $before = array_map(fn ($model) => $model->fresh()->getRawOriginal(), $protected);
        $unrelated = Restaurant::create(['name' => 'Other', 'slug' => 'other', 'address' => 'Untouched']);
        $this->artisan('menu:install', ['--restaurant' => ['location-1'], '--apply' => true])->assertSuccessful();
        $this->assertSame($before, array_map(fn ($model) => $model->fresh()->getRawOriginal(), $protected));
        $this->assertSame(0, $unrelated->menuCategories()->count());
        $this->assertSame('17.00', app(ConsolidatedPreOrderService::class)->summarize($reservation)['grand_total']);
        $response = $this->getJson(route('invitations.menu', $token))->assertOk();
        $this->assertSame('Appetizers', $response->json('catalog.0.name'));
        $this->assertCount(8, $response->json('catalog.0.dishes'));
        $fresh = MenuItem::where('name', 'Fresh Strawberry Mint Salad')->sole();
        $this->putJson(route('invitations.selections.update', $token), ['items' => [['menu_item_id' => $fresh->id, 'quantity' => 2]]], ['If-Match' => '"1"'])->assertOk()->assertJsonPath('subtotal', '34.00');
        $this->putJson(route('invitations.selections.update', $token), ['items' => []], ['If-Match' => '"1"'])->assertConflict();
        $soldOut = MenuItem::where('name', 'Green Asparagus Salad')->sole();
        $this->putJson(route('invitations.selections.update', $token), ['items' => [['menu_item_id' => $soldOut->id, 'quantity' => 1]]], ['If-Match' => '"2"'])->assertUnprocessable();
    }
}
