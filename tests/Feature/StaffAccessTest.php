<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_entry_is_visible_to_guests_but_workspace_still_requires_authentication(): void
    {
        $this->withoutVite();
        $this->get('/staff')->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/Login'));
        $this->get('/staff/enter')->assertRedirect('/staff/login')->assertSessionHas('url.intended', url('/staff/enter'));
    }

    public function test_staff_sign_in_returns_to_workspace_and_customers_never_gain_staff_access(): void
    {
        $this->withoutVite();
        $restaurant = Restaurant::factory()->create(['name' => 'Staff Restaurant', 'slug' => 'staff-restaurant', 'address' => 'Address', 'is_active' => true]);
        $staff = User::factory()->create(['password' => Hash::make('StaffLogin2026!')]);
        $staff->staffRestaurants()->attach($restaurant, ['role' => 'waiter', 'is_active' => true]);
        $this->get('/staff/enter')->assertRedirect('/staff/login');
        $this->postJson('/login', ['email' => $staff->email, 'password' => 'StaffLogin2026!'])->assertOk()->assertJsonPath('redirect', url('/staff/enter'));
        $this->get('/staff/enter')->assertRedirect('/staff/restaurants/'.$restaurant->id.'/reservations');
        $this->get('/staff')->assertInertia(fn (Assert $page) => $page->has('workplaces', 1)->where('workplaces.0.id', $restaurant->id));
        $customer = User::factory()->create();
        $this->flushSession();
        $this->actingAs($customer)->get('/staff')->assertInertia(fn (Assert $page) => $page->has('workplaces', 0));
        $this->get('/staff/enter')->assertRedirect('/staff');
        $this->getJson('/staff/restaurants/'.$restaurant->id.'/reservations')->assertForbidden();
        $this->assertDatabaseCount('restaurant_staff', 1);
    }

    public function test_revoked_membership_and_inactive_restaurants_are_not_offered(): void
    {
        $this->withoutVite();
        $staff = User::factory()->create();
        $restaurant = Restaurant::factory()->create(['name' => 'Restaurant', 'slug' => 'hidden', 'address' => 'Address', 'is_active' => false]);
        $staff->staffRestaurants()->attach($restaurant, ['role' => 'waiter', 'is_active' => true]);
        $this->actingAs($staff)->get('/staff')->assertInertia(fn (Assert $page) => $page->has('workplaces', 0));
        $restaurant->update(['is_active' => true]);
        $staff->staffRestaurants()->updateExistingPivot($restaurant->id, ['is_active' => false]);
        $this->get('/staff/enter')->assertRedirect('/staff');
    }

    public function test_cli_preview_is_read_only_and_setup_preserves_existing_password_bookings_and_roles(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Restaurant', 'slug' => 'location-1', 'address' => 'Address', 'is_active' => true]);
        $customer = User::factory()->create();
        $password = $customer->password;
        $this->artisan('staff:setup', ['email' => 'presenter@example.test', '--restaurant' => 'location-1', '--create' => true])->assertSuccessful();
        $this->assertDatabaseMissing('users', ['email' => 'presenter@example.test']);
        $this->assertDatabaseCount('restaurant_staff', 0);
        $this->artisan('staff:setup', ['email' => $customer->email, '--restaurant' => 'location-1', '--apply' => true])->assertSuccessful();
        $this->assertSame($password, $customer->fresh()->password);
        $this->assertTrue($customer->fresh()->can('staff', $restaurant));
        $this->artisan('staff:setup', ['email' => 'presenter@example.test', '--restaurant' => 'location-1', '--create' => true, '--apply' => true])->assertSuccessful();
        $presenter = User::where('email', 'presenter@example.test')->sole();
        $saved = $presenter->password;
        $this->assertTrue($presenter->hasVerifiedEmail());
        $this->assertTrue($presenter->can('staff', $restaurant));
        $this->artisan('staff:setup', ['email' => 'presenter@example.test', '--restaurant' => 'location-1', '--create' => true, '--apply' => true])->assertSuccessful();
        $this->assertSame($saved, $presenter->fresh()->password);
        $this->assertDatabaseCount('restaurant_staff', 2);
        $this->assertDatabaseCount('reservations', 0);
        $unverified = User::factory()->unverified()->create();
        $this->artisan('staff:setup', ['email' => $unverified->email, '--restaurant' => 'location-1', '--apply' => true])->assertFailed();
    }

    public function test_cli_refuses_implicit_creation_missing_restaurants_and_replacing_other_roles(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Restaurant', 'slug' => 'location-1', 'address' => 'Address', 'is_active' => true]);
        $this->artisan('staff:setup', ['email' => 'missing@example.test', '--restaurant' => 'location-1', '--apply' => true])->assertFailed();
        $this->artisan('staff:setup', ['email' => 'missing@example.test', '--restaurant' => 'missing', '--create' => true, '--apply' => true])->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'missing@example.test']);
        $manager = User::factory()->create();
        $manager->staffRestaurants()->attach($restaurant, ['role' => 'manager', 'is_active' => true]);
        $this->artisan('staff:setup', ['email' => $manager->email, '--restaurant' => 'location-1', '--apply' => true])->assertFailed();
        $this->assertDatabaseHas('restaurant_staff', ['user_id' => $manager->id, 'role' => 'manager']);
    }
}
