<?php

namespace Tests\Feature\Auth;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        Queue::fake();
        config(['staff.registration_enabled' => true, 'staff.registration_restaurant' => 'location-1']);
        $this->restaurant = Restaurant::factory()->create(['name' => 'Green Tasty', 'slug' => 'location-1', 'address' => '48 Rustaveli Avenue', 'is_active' => true]);
    }

    /** @return array<string, string> */
    private function details(): array
    {
        return ['first_name' => 'New', 'last_name' => 'Waiter', 'email' => 'new.staff@example.test', 'password' => 'Staff9!Pass', 'password_confirmation' => 'Staff9!Pass'];
    }

    public function test_form_reports_the_actual_workplace_without_exposing_staff_accounts(): void
    {
        $this->get('/staff/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/Login')->where('registration.enabled', true)->where('registration.restaurant.name', 'Green Tasty')->where('registration.restaurant.address', '48 Rustaveli Avenue')->missing('registration.users'));
        $this->get('/staff')->assertOk()->assertInertia(fn (Assert $page) => $page->where('registration.restaurant.address', '48 Rustaveli Avenue'));
    }

    public function test_demo_registration_creates_a_scoped_waiter_and_signs_in_without_codes_or_mail(): void
    {
        $other = Restaurant::factory()->create(['name' => 'Other', 'slug' => 'other', 'address' => 'Other address', 'is_active' => true]);
        $before = session()->getId();
        $this->withSession(['url.intended' => url('/settings/profile')])->postJson('/staff/register', [
            ...$this->details(), 'email' => ' NEW.STAFF@EXAMPLE.TEST ', 'role' => 'manager', 'restaurant_id' => $other->id, 'restaurants' => [$other->id], 'is_admin' => true,
        ])->assertCreated()->assertJsonPath('next', 'authenticated')->assertJsonPath('redirect', route('staff.entry'))->assertJsonMissingPath('challenge')->assertHeader('Cache-Control', 'no-store, private');
        $user = User::where('email', 'new.staff@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
        $this->assertTrue($user->hasStaffAccess());
        $this->assertTrue(Hash::check('Staff9!Pass', $user->password));
        $this->assertSame($user->password, session('password_hash_web'));
        $this->assertFalse(session()->has('url.intended'));
        $this->assertDatabaseHas('restaurant_staff', ['user_id' => $user->id, 'restaurant_id' => $this->restaurant->id, 'role' => 'waiter', 'is_active' => true]);
        $this->assertDatabaseCount('restaurant_staff', 1);
        $this->assertDatabaseCount('auth_challenges', 0);
        $this->assertDatabaseCount('reservations', 0);
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $this->get('/staff/enter')->assertRedirect('/staff/restaurants/'.$this->restaurant->id.'/reservations');
        $this->get('/staff/restaurants/'.$this->restaurant->id.'/reservations')->assertOk();
        $this->getJson('/staff/restaurants/'.$other->id.'/reservations')->assertForbidden();
        $this->post('/logout')->assertRedirect('/');
        $this->postJson('/staff/login', ['email' => $user->email, 'password' => 'Staff9!Pass'])->assertOk()->assertJsonPath('next', 'authenticated');
    }

    public function test_existing_customer_cannot_be_overwritten_or_promoted_by_registration(): void
    {
        $customer = User::factory()->create(['email' => $this->details()['email']]);
        $password = $customer->password;
        $this->postJson('/staff/register', $this->details())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        $this->assertSame($password, $customer->fresh()->password);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('restaurant_staff', 0);
    }

    public function test_disabled_missing_or_inactive_workplace_never_creates_an_orphan_account(): void
    {
        config(['staff.registration_enabled' => false]);
        $this->postJson('/staff/register', $this->details())->assertForbidden();
        $this->get('/staff/login')->assertInertia(fn (Assert $page) => $page->where('registration.enabled', false)->where('registration.restaurant', null));
        config(['staff.registration_enabled' => true, 'staff.registration_restaurant' => 'missing']);
        $this->postJson('/staff/register', $this->details())->assertUnprocessable()->assertJsonValidationErrors('restaurant');
        config(['staff.registration_restaurant' => 'location-1']);
        $this->restaurant->update(['is_active' => false]);
        $this->postJson('/staff/register', $this->details())->assertUnprocessable()->assertJsonValidationErrors('restaurant');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('restaurant_staff', 0);
    }

    public function test_registration_rejects_malformed_identity_and_weak_or_mismatched_passwords(): void
    {
        foreach ([['email' => ['invalid']], ['first_name' => 'Bad@'], ['password' => 'weak', 'password_confirmation' => 'weak'], ['password_confirmation' => 'Different9!']] as $invalid) {
            $this->postJson('/staff/register', [...$this->details(), ...$invalid])->assertUnprocessable();
        }
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('restaurant_staff', 0);
    }

    public function test_csrf_is_required_and_staff_registration_rotates_the_session(): void
    {
        $this->app->instance('env', 'local');
        $page = $this->get('/staff/login')->assertOk();
        $xsrf = $page->getCookie('XSRF-TOKEN', false)->getValue();
        $this->postJson('/staff/register', $this->details())->assertStatus(419)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('users', 0);
        $before = session()->getId();
        $this->postJson('/staff/register', $this->details(), ['X-XSRF-TOKEN' => $xsrf])->assertCreated();
        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticated();
    }

    public function test_visitor_and_staff_registration_share_the_ip_rate_limit(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/staff/register', ['email' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/staff/register', $this->details())->assertTooManyRequests()->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/register', $this->details())->assertTooManyRequests();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_authenticated_visitors_cannot_create_staff_accounts_inside_their_existing_session(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->postJson('/staff/register', $this->details())->assertRedirect();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('restaurant_staff', 0);
    }
}
