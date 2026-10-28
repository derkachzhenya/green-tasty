<?php

namespace Tests\Feature\Auth;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffLoginTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Restaurant', 'slug' => 'staff-place', 'address' => 'Address', 'is_active' => true]);
        $user = User::factory()->create();
        $user->staffRestaurants()->attach($restaurant, ['role' => 'waiter', 'is_active' => true]);

        return $user;
    }

    public function test_separate_staff_form_has_security_headers_and_protected_links_use_it(): void
    {
        $this->withoutVite();
        $this->get('/staff/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/Login'))->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/staff/restaurants/1/reservations')->assertRedirect('/staff/login');
        $this->get('/reservations')->assertRedirect('/login');
    }

    public function test_staff_credentials_authenticate_and_override_a_stale_customer_destination(): void
    {
        $staff = $this->staff();
        $this->withSession(['url.intended' => url('/settings/profile')])->postJson('/staff/login', ['email' => strtoupper($staff->email), 'password' => 'password'])
            ->assertOk()->assertJsonPath('next', 'authenticated')->assertJsonPath('redirect', route('staff.entry'));
        $this->assertAuthenticatedAs($staff);
        $this->assertNotNull(session('password_hash_web'));
        $this->assertFalse(session()->has('url.intended'));
        $this->get('/staff/login')->assertRedirect('/staff');
        $this->get('/staff/enter')->assertRedirect('/staff/restaurants/'.$staff->staffRestaurants()->sole()->id.'/reservations');
    }

    public function test_customer_credentials_are_rejected_without_creating_a_session_or_granting_a_role(): void
    {
        $customer = User::factory()->create();
        $this->postJson('/staff/login', ['email' => $customer->email, 'password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('restaurant_staff', 0);
        $this->postJson('/login', ['email' => $customer->email, 'password' => 'password'])->assertOk()->assertJsonPath('redirect', url('/reservations'));
        $this->assertAuthenticatedAs($customer);
    }

    public function test_switching_back_to_the_visitor_form_does_not_send_a_customer_to_a_protected_staff_url(): void
    {
        $this->withoutVite();
        $customer = User::factory()->create();
        $this->get('/staff/restaurants/1/reservations')->assertRedirect('/staff/login');
        $this->get('/staff/login')->assertOk()->assertSessionMissing('url.intended');
        $this->postJson('/login', ['email' => $customer->email, 'password' => 'password'])->assertOk()->assertJsonPath('redirect', url('/reservations'));
        $this->assertAuthenticatedAs($customer);
    }

    public function test_inactive_staff_wrong_password_and_unverified_accounts_cannot_sign_in_as_staff(): void
    {
        $staff = $this->staff();
        $this->postJson('/staff/login', ['email' => $staff->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->assertGuest();
        $staff->staffRestaurants()->updateExistingPivot($staff->staffRestaurants()->sole()->id, ['is_active' => false]);
        $this->postJson('/staff/login', ['email' => $staff->email, 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest();
        $staff->staffRestaurants()->updateExistingPivot($staff->staffRestaurants()->sole()->id, ['is_active' => true]);
        $staff->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/staff/login', ['email' => $staff->email, 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest();
        $this->assertDatabaseCount('auth_challenges', 0);
    }

    public function test_staff_and_customer_forms_share_the_failed_attempt_limit(): void
    {
        $staff = $this->staff();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/staff/login', ['email' => $staff->email, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => $staff->email, 'password' => 'password'])->assertTooManyRequests();
        $this->postJson('/staff/login', ['email' => $staff->email, 'password' => 'password'])->assertTooManyRequests()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
    }

    public function test_staff_using_the_common_sign_in_also_reach_the_staff_workspace(): void
    {
        $staff = $this->staff();
        $this->postJson('/login', ['email' => $staff->email, 'password' => 'password'])->assertOk()->assertJsonPath('redirect', route('staff.entry'));
        $this->assertAuthenticatedAs($staff);
    }
}
