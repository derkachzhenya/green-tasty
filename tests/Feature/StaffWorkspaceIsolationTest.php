<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Restaurant $restaurant;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->create();
        $this->restaurant = Restaurant::factory()->create(['name' => 'Workplace', 'slug' => 'location-1', 'address' => '48 Rustaveli Avenue', 'is_active' => true]);
        $this->staff->staffRestaurants()->attach($this->restaurant, ['role' => 'waiter', 'is_active' => true]);
        $table = $this->restaurant->tables()->create(['name' => 'Table 1', 'seats' => 4]);
        // Even historical personal bookings must not reopen customer actions for staff.
        $this->reservation = $this->restaurant->reservations()->create(['owner_user_id' => $this->staff->id, 'restaurant_table_id' => $table->id, 'code' => 'GT-STAFF-LEGACY', 'reserved_at' => now()->addDays(2), 'duration_minutes' => 120, 'guest_count' => 2, 'status' => 'confirmed']);
    }

    public function test_staff_customer_page_visits_return_to_their_workspace_including_direct_and_inertia_links(): void
    {
        $this->actingAs($this->staff);
        foreach (['/', '/book', '/restaurants/location-1', '/restaurants/location-1/book', '/reservations', '/dashboard', '/reservations/'.$this->reservation->id, '/reservations/'.$this->reservation->id.'/confirmation'] as $url) {
            $this->get($url)->assertRedirect('/staff/enter')->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->get('/book', ['X-Inertia' => 'true'])->assertRedirect('/staff/enter');
        $this->get('/staff/enter')->assertRedirect('/staff/restaurants/'.$this->restaurant->id.'/reservations');
        $this->get('/staff/restaurants/'.$this->restaurant->id.'/reservations')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.isStaff', true));
    }

    public function test_staff_cannot_read_customer_apis_or_execute_customer_mutations(): void
    {
        $this->actingAs($this->staff);
        foreach (['/book/availability', '/restaurants/location-1/availability', '/reservations', '/reservations/'.$this->reservation->id.'/pre-order/summary'] as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('redirect', route('staff.entry'));
        }
        $root = '/reservations/'.$this->reservation->id;
        foreach (['/book/continue', '/restaurants/location-1/book', $root.'/cancel', $root.'/invitations', $root.'/pre-order/submit', $root.'/owner-pre-order/submit'] as $url) {
            $this->postJson($url, [])->assertForbidden();
        }
        $this->patchJson($root.'/booking', [])->assertForbidden();
        $this->putJson($root.'/feedback', [])->assertForbidden();
        $this->putJson($root.'/owner-pre-order/selections', [])->assertForbidden();
        $this->assertSame('confirmed', $this->reservation->fresh()->status);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertDatabaseCount('reservation_guests', 0);
    }

    public function test_staff_menu_is_scoped_includes_sold_out_dishes_and_never_exposes_other_restaurants(): void
    {
        $category = $this->restaurant->menuCategories()->create(['name' => 'Salads', 'slug' => 'salads', 'is_active' => true]);
        $category->items()->create(['name' => 'Fresh Strawberry Mint Salad', 'price' => '17.00', 'is_available' => true]);
        $category->items()->create(['name' => 'Sold out salad', 'price' => '12.50', 'is_available' => false]);
        $hidden = $this->restaurant->menuCategories()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);
        $hidden->items()->create(['name' => 'Hidden dish', 'price' => '9.00']);
        $other = Restaurant::factory()->create(['name' => 'Other', 'slug' => 'other', 'address' => 'Other address', 'is_active' => true]);
        $this->actingAs($this->staff)->get('/staff/restaurants/'.$this->restaurant->id.'/menu')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('staff/Menu')->where('auth.isStaff', true)->has('categories', 1)->has('categories.0.dishes', 2)->where('categories.0.dishes.1.available', false)->where('categories.0.dishes.1.price', '12.50'));
        $this->get('/staff/restaurants/'.$other->id.'/menu')->assertForbidden();
        $this->restaurant->update(['is_active' => false]);
        $this->get('/staff/restaurants/'.$this->restaurant->id.'/menu')->assertForbidden();
    }

    public function test_customers_and_guests_keep_their_existing_routes_and_cannot_open_staff_menu(): void
    {
        $this->get('/')->assertOk();
        $this->get('/book')->assertOk();
        $this->get('/staff/restaurants/'.$this->restaurant->id.'/menu')->assertRedirect('/staff/login');
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/book')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.isStaff', false));
        $this->get('/reservations')->assertOk();
        $this->get('/restaurants/location-1')->assertOk();
        $this->get('/staff/restaurants/'.$this->restaurant->id.'/menu')->assertForbidden();
    }

    public function test_revoked_staff_remain_staff_and_do_not_fall_back_to_customer_pages(): void
    {
        $this->staff->staffRestaurants()->updateExistingPivot($this->restaurant->id, ['is_active' => false]);
        $this->actingAs($this->staff)->get('/reservations')->assertRedirect('/staff/enter');
        $this->get('/staff/enter')->assertRedirect('/staff');
        $this->get('/staff')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.isStaff', true)->has('workplaces', 0));
        $this->get('/staff/restaurants/'.$this->restaurant->id.'/menu')->assertForbidden();
    }
}
