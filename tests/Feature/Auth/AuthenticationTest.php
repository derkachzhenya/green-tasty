<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_signing_in_from_an_old_reservation_link_opens_the_figma_reservation_list(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->get('/reservations/19')->assertRedirect('/login');
        $this->assertSame(url('/reservations/19'), session('url.intended'));
        $this->get('/login')->assertOk();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('next', 'authenticated')
            ->assertJsonPath('redirect', route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(session()->has('url.intended'));
        $this->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('dashboardPage', false));
    }

    #[DataProvider('obsoleteReservationDestinations')]
    public function test_html_login_does_not_restore_an_obsolete_reservation_screen(string $path): void
    {
        $user = User::factory()->create();
        $this->withSession(['url.intended' => url($path)])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertFalse(session()->has('url.intended'));
    }

    public static function obsoleteReservationDestinations(): array
    {
        return [
            'details' => ['/reservations/19'],
            'group anchor' => ['/reservations/19#group-pre-order'],
            'confirmation' => ['/reservations/19/confirmation'],
            'trailing slash and query' => ['/reservations/19/?from=login'],
        ];
    }

    public function test_login_preserves_a_selected_table_for_an_unfinished_booking(): void
    {
        $user = User::factory()->create();
        $destination = url('/book?restaurant=location-1&date=2026-11-11&time=12%3A00&guest_count=2&table=1');
        $this->withSession(['url.intended' => $destination])
            ->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('redirect', $destination);
        $this->assertFalse(session()->has('url.intended'));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }
}
