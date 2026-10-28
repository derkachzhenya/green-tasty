<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        Notification::fake();
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Secure9!Pass',
            'password_confirmation' => 'Secure9!Pass',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard'));
        Notification::assertNothingSent();
    }

    public function test_registration_signs_in_without_mail_or_codes_preserves_the_booking_destination_and_never_grants_staff(): void
    {
        $this->withoutVite();
        Notification::fake();
        Queue::fake();
        config(['mail.default' => 'log']);
        $before = session()->getId();
        $destination = url('/book?restaurant=location-1&date=2026-11-11&time=12%3A00&guest_count=2&table=1');
        $this->withSession(['url.intended' => $destination])->postJson('/register', [
            'first_name' => 'New', 'last_name' => 'Visitor', 'email' => 'new.visitor@example.test',
            'password' => 'Secure9!Pass', 'password_confirmation' => 'Secure9!Pass',
            'role' => 'waiter', 'is_staff' => true,
        ])->assertCreated()->assertJsonPath('next', 'authenticated')->assertJsonPath('redirect', $destination)->assertJsonMissingPath('challenge');
        $user = User::where('email', 'new.visitor@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
        $this->assertTrue(Hash::check('Secure9!Pass', $user->password));
        $this->assertNotSame('Secure9!Pass', $user->password);
        $this->assertSame($user->password, session('password_hash_web'));
        $this->assertFalse($user->hasStaffAccess());
        $this->assertDatabaseCount('restaurant_staff', 0);
        $this->assertDatabaseCount('auth_challenges', 0);
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $this->get('/reservations')->assertOk();
        $this->post('/logout')->assertRedirect('/');
        $this->postJson('/staff/login', ['email' => $user->email, 'password' => 'Secure9!Pass'])->assertUnprocessable();
        $this->assertGuest();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'Secure9!Pass'])->assertOk()->assertJsonPath('next', 'authenticated');
        Notification::assertNothingSent();
    }

    public function test_registration_also_discards_a_stale_reservation_detail_destination(): void
    {
        Notification::fake();
        $this->withSession(['url.intended' => url('/reservations/19')])->postJson('/register', [
            'first_name' => 'New', 'last_name' => 'Visitor', 'email' => 'new.list@example.test',
            'password' => 'Secure9!Pass', 'password_confirmation' => 'Secure9!Pass',
        ])->assertCreated()->assertJsonPath('next', 'authenticated')
            ->assertJsonPath('redirect', route('dashboard'));
        $this->assertAuthenticated();
        Notification::assertNothingSent();
    }
}
