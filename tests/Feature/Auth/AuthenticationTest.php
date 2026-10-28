<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    #[DataProvider('staleReservationDestinations')]
    public function test_login_replaces_stale_reservation_destinations_with_the_reservations_dashboard(string $path, bool $json): void
    {
        $user = User::factory()->create();
        $this->withSession(['url.intended' => url('/').$path]);
        $credentials = ['email' => $user->email, 'password' => 'password'];

        $response = $json ? $this->postJson(route('login.store'), $credentials) : $this->post(route('login.store'), $credentials);

        if ($json) {
            $response->assertOk()->assertJsonPath('next', 'authenticated')->assertJsonPath('redirect', route('dashboard'));
        } else {
            $response->assertRedirect(route('dashboard'));
        }
        $this->assertAuthenticatedAs($user);
        $response->assertSessionMissing('url.intended');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function staleReservationDestinations(): iterable
    {
        foreach ([
            'details' => '/reservations/19',
            'details with group anchor' => '/reservations/19#group-pre-order',
            'details with query and trailing slash' => '/reservations/19/?from=login',
            'confirmation' => '/reservations/19/confirmation',
            'confirmation with query and anchor' => '/reservations/19/confirmation/?from=login#booking',
        ] as $name => $path) {
            yield $name.' HTML' => [$path, false];
            yield $name.' JSON' => [$path, true];
        }
    }

    #[DataProvider('supportedLoginDestinations')]
    public function test_login_preserves_the_default_and_other_intended_destinations(?string $path, bool $json): void
    {
        $user = User::factory()->create();
        $destination = $path === null ? route('dashboard') : url($path);
        if ($path !== null) {
            $this->withSession(['url.intended' => $destination]);
        }
        $credentials = ['email' => $user->email, 'password' => 'password'];

        $response = $json ? $this->postJson(route('login.store'), $credentials) : $this->post(route('login.store'), $credentials);

        if ($json) {
            $response->assertOk()->assertJsonPath('next', 'authenticated')->assertJsonPath('redirect', $destination);
        } else {
            $response->assertRedirect($destination);
        }
        $this->assertAuthenticatedAs($user);
        $response->assertSessionMissing('url.intended');
    }

    /** @return iterable<string, array{string|null, bool}> */
    public static function supportedLoginDestinations(): iterable
    {
        foreach ([
            'default' => null,
            'filtered reservations' => '/reservations?status=upcoming&page=2',
            'account dashboard' => '/dashboard',
            'pending table selection' => '/book?restaurant=location-1&date=2026-11-11&time=12%3A00&guest_count=2&table=1',
            'restaurant booking' => '/restaurants/location-1/book',
            'owner pre-order' => '/reservations/19/owner-pre-order',
            'group pre-order' => '/reservations/19/pre-order/owner-view',
            'account settings' => '/settings/profile',
        ] as $name => $path) {
            yield $name.' HTML' => [$path, false];
            yield $name.' JSON' => [$path, true];
        }
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
