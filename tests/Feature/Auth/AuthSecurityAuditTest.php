<?php

namespace Tests\Feature\Auth;

use App\Models\AuthChallenge;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\AuthVerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
        $this->withoutVite();
    }

    public function test_stale_registration_session_cannot_reauthorize_itself_by_resending_after_a_password_reset(): void
    {
        $user = User::factory()->unverified()->create();
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertOk();
        $originalSession = session('auth.codes.registration');
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $code = Notification::sent(new AnonymousNotifiable, AuthVerificationCode::class)->last()->code;
        $this->postJson(route('auth.code.verify'), ['purpose' => 'recovery', 'code' => $code])->assertOk();
        $this->postJson(route('password.update'), ['password' => 'Recovered9!Pass', 'password_confirmation' => 'Recovered9!Pass'])->assertOk();
        $this->travel(61)->seconds();
        $this->withSession(['auth' => ['codes' => ['registration' => $originalSession]]])->postJson(route('auth.code.resend'), ['purpose' => 'registration'])->assertUnprocessable();
        $this->patchJson(route('auth.registration.update'), ['first_name' => 'Stale', 'last_name' => 'Attacker', 'email' => 'attacker@example.test', 'password' => 'Attacker9!Pass', 'password_confirmation' => 'Attacker9!Pass'])->assertUnprocessable();
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_existing_unverified_sessions_cannot_access_private_reservation_endpoints(): void
    {
        $owner = User::factory()->unverified()->create();
        $restaurant = Restaurant::create(['name' => 'Audit', 'slug' => 'audit', 'address' => 'Test']);
        $reservation = $restaurant->reservations()->create(['owner_user_id' => $owner->id, 'code' => 'AUDIT-1', 'reserved_at' => now()->addDay(), 'guest_count' => 1]);
        $this->actingAs($owner);
        foreach ([route('reservations.show', $reservation), route('reservations.pre-order.summary', $reservation), route('restaurants.bookings.create', $restaurant), route('restaurants.availability', $restaurant)] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson(route('reservations.pre-order.submit', $reservation))->assertForbidden();
        $this->postJson(route('reservations.invitations.store', $reservation), ['name' => 'Guest'])->assertForbidden();
        $this->postJson(route('restaurants.bookings.store', $restaurant), [])->assertForbidden();
        $this->assertDatabaseCount('reservation_guests', 0);
        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_verification_notice_get_is_read_only_and_does_not_send_email_or_log_out(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        $this->assertAuthenticatedAs($user);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('auth_challenges', 0);
    }

    public function test_a_worker_cannot_deliver_codes_to_a_log_mailer_after_configuration_changes(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize(Crypt::decrypt($payload['data']['command']));
        config(['mail.default' => 'log']);
        Log::shouldReceive('warning')->once()->with('Authentication email delivery failed.', ['type' => ValidationException::class]);
        $job->handle();
        Notification::assertNothingSent();
        $this->assertNull(AuthChallenge::sole()->code_hash);
    }

    public function test_a_mail_url_cannot_override_a_safe_transport_with_a_log_transport(): void
    {
        foreach (['log://localhost', 'smtp://localhost?driver=log'] as $url) {
            config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => $url]);
            $this->postJson(route('password.email'), ['email' => 'audit@example.test'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('auth_challenges', 0);
        config(['mail.mailers.smtp.url' => 'smtp://127.0.0.1:1026']);
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        Notification::assertSentOnDemandOnce(AuthVerificationCode::class);
    }

    public function test_password_changes_invalidate_an_existing_authenticated_session_even_without_database_session_deletion(): void
    {
        $user = User::factory()->create();
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertOk();
        $user->forceFill(['password' => 'Changed9!Pass'])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson(route('dashboard'))->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_mysql_email_collation_aliases_cannot_bypass_the_login_lockout(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the MySQL email collation used in production.');
        }
        $user = User::factory()->create(['email' => 'audit@example.test']);
        $alias = 'áudit@example.test';
        $this->assertSame($user->id, User::where('email', $alias)->sole()->id);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson(route('login.store'), ['email' => $alias, 'password' => 'password'])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_legacy_verification_continuation_requires_csrf_and_ends_the_unverified_session(): void
    {
        $this->app->instance('env', 'local');
        $user = User::factory()->unverified()->create();
        $notice = $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        $this->postJson(route('verification.send'))->assertStatus(419);
        $this->assertAuthenticatedAs($user);
        $xsrf = $notice->getCookie('XSRF-TOKEN', false)->getValue();
        $sent = $this->postJson(route('verification.send'), [], ['X-XSRF-TOKEN' => $xsrf])->assertOk()->assertJsonPath('next', 'verify');
        $this->assertGuest();
        $code = Notification::sent(new AnonymousNotifiable, AuthVerificationCode::class)->sole()->code;
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $code], ['X-XSRF-TOKEN' => $sent->getCookie('XSRF-TOKEN', false)->getValue()])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_malformed_legacy_link_input_redirects_without_exposing_or_flashing_the_token(): void
    {
        $token = 'legacy-secret-token';
        $this->get(route('password.reset', ['token' => $token, 'email' => ['invalid']]))->assertRedirect(route('password.request'))->assertDontSee($token)->assertSessionMissing('_old_input.token');
    }

    public function test_recovery_never_sends_mail_to_a_collation_equivalent_unregistered_address(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the MySQL email collation used in production.');
        }
        User::factory()->create(['email' => 'audit@example.test']);
        $this->postJson(route('password.email'), ['email' => 'áudit@example.test'])->assertOk()->assertJsonPath('next', 'verify');
        Notification::assertNothingSent();
        $this->assertNull(AuthChallenge::sole()->user_id);
    }

    public function test_recovery_verification_rotates_the_session_and_grant_and_rejects_preverification_session_data(): void
    {
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $oldId = session()->getId();
        $oldGrant = session('auth.codes.recovery');
        $code = Notification::sent(new AnonymousNotifiable, AuthVerificationCode::class)->sole()->code;
        $this->postJson(route('auth.code.verify'), ['purpose' => 'recovery', 'code' => $code])->assertOk();
        $grant = session('auth.codes.recovery');
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldGrant['generation'], $grant['generation']);
        $this->get(route('auth.verify'))->assertRedirect(route('auth.reset'));
        $body = ['password' => 'Recovered9!Pass', 'password_confirmation' => 'Recovered9!Pass'];
        $this->withSession(['auth' => ['codes' => ['recovery' => $oldGrant]]])->postJson(route('password.update'), $body)->assertUnprocessable();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->withSession(['auth' => ['codes' => ['recovery' => $grant]]])->postJson(route('password.update'), $body)->assertOk();
        $this->assertTrue(Hash::check('Recovered9!Pass', $user->fresh()->password));
    }
}
