<?php

namespace Tests\Feature\Auth;

use App\Jobs\DeliverAuthCode;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Notifications\AuthVerificationCode;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthCodeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
    }

    private function code(): string
    {
        $notification = Notification::sent(new AnonymousNotifiable, AuthVerificationCode::class)->last();
        $this->assertInstanceOf(AuthVerificationCode::class, $notification);

        return $notification->code;
    }

    private function register(): User
    {
        // Exercise still-supported legacy challenges independently of the new
        // self-registration flow, which signs in immediately without mail.
        $user = User::factory()->unverified()->create(['name' => "Test O'Brien", 'email' => 'test@example.com', 'password' => 'Secure9!Pass']);
        $this->actingAs($user)->postJson(route('verification.send'))->assertOk()->assertJsonPath('next', 'verify');

        return User::where('email', 'test@example.com')->sole();
    }

    private function details(): array
    {
        return ['first_name' => 'Test', 'last_name' => "O'Brien", 'email' => 'test@example.com', 'password' => 'Secure9!Pass', 'password_confirmation' => 'Secure9!Pass'];
    }

    public function test_legacy_registration_challenges_remain_single_use_and_do_not_expose_secrets(): void
    {
        $user = $this->register();
        $this->assertGuest();
        $this->assertNull($user->email_verified_at);
        $code = $this->code();
        $this->assertMatchesRegularExpression('/^[A-F0-9]{16}$/', $code);
        $row = AuthChallenge::sole();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertSame(64, strlen($row->code_hash));
        $this->assertStringNotContainsString($code, json_encode($row->getAttributes()));
        $this->get(route('auth.verify'))->assertOk()->assertDontSee($code)->assertDontSee($row->code_hash)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page->component('auth/Flow')->where('initialStep', 'verify')->where('challenge.email', $user->email)->missing('challenge.code')->missing('challenge.token'));
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $code])->assertOk()->assertJsonPath('next', 'login');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull($row->fresh()->code_hash);
        $this->assertGuest();
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $code])->assertUnprocessable();
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'Secure9!Pass'])->assertOk()->assertJsonPath('next', 'authenticated');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('You have signed in successfully.', session('inertia.flash_data.authNotification'));
    }

    public function test_unknown_and_known_recovery_addresses_have_identical_public_responses(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);
        $known = $this->postJson(route('password.email'), ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson(route('password.email'), ['email' => 'unknown@example.com'])->assertOk()->json();
        $known['challenge']['email'] = $unknown['challenge']['email'];
        $this->assertSame($known, $unknown);
        Notification::assertSentOnDemandOnce(AuthVerificationCode::class);
        $this->postJson(route('auth.code.verify'), ['purpose' => 'recovery', 'code' => $this->code()])->assertUnprocessable();
    }

    public function test_recovery_uses_fortify_reset_rotates_remember_token_and_consumes_session_grant(): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create(['remember_token' => 'previous-remember-token']);
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $code = $this->code();
        $password = ['password' => 'NewSecure9!Pass', 'password_confirmation' => 'NewSecure9!Pass'];
        $this->postJson(route('password.update'), $password)->assertUnprocessable();
        $this->postJson(route('auth.code.verify'), ['purpose' => 'recovery', 'code' => $code])->assertOk()->assertJsonPath('next', 'reset');
        $this->get(route('auth.reset'))->assertOk()->assertDontSee($code)->assertInertia(fn (Assert $page) => $page->missing('token'));
        $this->postJson(route('password.update'), $password)->assertOk()->assertJsonPath('next', 'login');
        $this->assertTrue(Hash::check($password['password'], $user->fresh()->password));
        $this->assertNotSame('previous-remember-token', $user->fresh()->remember_token);
        Event::assertDispatched(PasswordReset::class);
        $this->assertGuest();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->postJson(route('password.update'), $password)->assertUnprocessable();
        $this->get(route('auth.reset'))->assertRedirect(route('password.request'));
    }

    public function test_wrong_attempts_are_persisted_and_fifth_failure_exhausts_the_code(): void
    {
        $this->register();
        $code = $this->code();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => 'WRONG'])->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->assertSame($attempt, AuthChallenge::sole()->attempts);
        }
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $code])->assertUnprocessable();
        $this->assertNull(User::sole()->email_verified_at);
    }

    public function test_expiration_boundary_resend_countdown_and_old_code_invalidation(): void
    {
        $this->register();
        $old = $this->code();
        $this->postJson(route('auth.code.resend'), ['purpose' => 'registration'])->assertTooManyRequests()->assertHeader('Retry-After', '60');
        $this->travel(60)->seconds();
        $this->postJson(route('auth.code.resend'), ['purpose' => 'registration'])->assertOk()->assertJsonPath('challenge.resend_after', 60);
        $new = $this->code();
        $this->assertNotSame($old, $new);
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $old])->assertUnprocessable();
        $this->travelTo(AuthChallenge::sole()->expires_at);
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $new])->assertUnprocessable();
        $this->postJson(route('auth.code.resend'), ['purpose' => 'registration'])->assertOk();
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $this->code()])->assertOk();
    }

    public function test_purposes_sessions_and_generation_are_isolated(): void
    {
        $user = $this->register();
        $registrationCode = $this->code();
        $session = session('auth.codes.registration');
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $this->postJson(route('auth.code.verify'), ['purpose' => 'recovery', 'code' => $registrationCode])->assertUnprocessable();
        $this->withSession(['auth' => []])->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $registrationCode])->assertUnprocessable();
        $this->withSession(['auth' => ['codes' => ['registration' => ['id' => $session['id'], 'generation' => 'forged']]]])
            ->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $registrationCode])->assertUnprocessable();
    }

    public function test_back_can_correct_pending_registration_without_creating_a_second_user(): void
    {
        $user = $this->register();
        $old = $this->code();
        $details = [...$this->details(), 'email' => 'corrected@example.com', 'first_name' => 'Correct'];
        $this->patchJson(route('auth.registration.update'), $details)->assertTooManyRequests();
        $this->assertSame('test@example.com', $user->fresh()->email);
        $this->travel(60)->seconds();
        $this->patchJson(route('auth.registration.update'), $details)->assertOk()->assertJsonPath('challenge.email', 'corrected@example.com');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Correct O\'Brien', $user->fresh()->name);
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $old])->assertUnprocessable();
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $this->code()])->assertOk();
        $this->patchJson(route('auth.registration.update'), $details)->assertUnprocessable();
    }

    public function test_unverified_login_resumes_code_flow_without_authenticated_access(): void
    {
        $user = User::factory()->unverified()->create();
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('next', 'verify');
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->getJson(route('auth.reset'))->assertRedirect(route('password.request'));
    }

    public function test_five_failed_logins_lock_for_five_minutes_and_success_restores_intended_navigation(): void
    {
        $user = User::factory()->create();
        $body = ['email' => $user->email, 'password' => 'incorrect'];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('login.store'), $body)->assertUnprocessable();
        }
        $valid = [...$body, 'password' => 'password'];
        $this->postJson(route('login.store'), $valid)->assertTooManyRequests()->assertHeader('Retry-After', '300')->assertJsonPath('retry_after', 300);
        $this->travel(299)->seconds();
        $this->postJson(route('login.store'), $valid)->assertTooManyRequests()->assertHeader('Retry-After', '1');
        $this->travel(1)->seconds();
        $this->withSession(['url.intended' => route('dashboard')])->postJson(route('login.store'), $valid)->assertOk()->assertJsonPath('redirect', route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_legacy_links_are_consumed_without_serializing_tokens_into_inertia(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
        $this->get($url)->assertRedirect(route('auth.reset'))->assertDontSee($token)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('auth.reset'))->assertOk()->assertDontSee($token)->assertInertia(fn (Assert $page) => $page->component('auth/Flow')->missing('token'));
        $this->get($url)->assertRedirect(route('password.request'));
    }

    public function test_validation_rejects_invalid_names_email_weak_password_and_mismatch(): void
    {
        $this->postJson(route('register.store'), [...$this->details(), 'first_name' => 'Test@', 'last_name' => 'User1', 'email' => 'test@com', 'password' => 'weak', 'password_confirmation' => 'different'])
            ->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password']);
        $this->assertDatabaseCount('users', 0);
        $this->postJson(route('register.store'), [])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password']);
        $this->postJson(route('auth.code.verify'), ['purpose' => 'other', 'code' => []])->assertUnprocessable()->assertJsonValidationErrors(['purpose', 'code']);
        $this->patchJson(route('auth.registration.update'), $this->details())->assertUnprocessable();
    }

    public function test_verified_recovery_grant_expires_and_password_change_invalidates_it(): void
    {
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $this->postJson(route('auth.code.verify'), ['purpose' => 'recovery', 'code' => $this->code()])->assertOk();
        $user->forceFill(['password' => 'Changed9!Pass'])->save();
        $this->postJson(route('password.update'), ['password' => 'NewSecure9!Pass', 'password_confirmation' => 'NewSecure9!Pass'])->assertUnprocessable();
        $this->assertTrue(Hash::check('Changed9!Pass', $user->fresh()->password));
        $this->travelTo(AuthChallenge::sole()->expires_at);
        $this->get(route('auth.reset'))->assertRedirect(route('password.request'));
    }

    public function test_authenticated_users_cannot_use_guest_code_endpoints(): void
    {
        $this->actingAs(User::factory()->create())->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => 'anything'])->assertRedirect();
        $this->assertDatabaseCount('auth_challenges', 0);
    }

    public function test_code_request_is_rate_limited_and_errors_do_not_flash_secrets(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => 'secret'])->assertUnprocessable();
        }
        $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => 'secret'])->assertTooManyRequests()->assertHeader('Cache-Control', 'no-store, private');
        Cache::flush();
        $this->post(route('auth.code.verify'), ['purpose' => 'registration', 'code' => 'secret'])->assertSessionMissing('_old_input.code');
    }

    public function test_log_and_log_fallback_mailers_cannot_receive_codes(): void
    {
        config(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['log']]]);
        $this->postJson(route('password.email'), ['email' => 'anything@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('auth_challenges', 0);
    }

    public function test_transport_failures_do_not_enumerate_recovery_accounts_or_log_email_contents(): void
    {
        $user = User::factory()->create();
        Log::shouldReceive('warning')->once()->with('Authentication email delivery failed.', ['type' => \RuntimeException::class]);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Transport included secret message body'));
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk()->assertJsonPath('next', 'verify')->assertDontSee('secret');
        $this->assertNull(AuthChallenge::sole()->code_hash);
        $this->postJson(route('password.email'), ['email' => 'missing@example.com'])->assertOk()->assertJsonPath('next', 'verify');
    }

    public function test_password_reset_invalidates_database_sessions_and_preserves_intended_location(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $this->postJson(route('auth.code.verify'), ['purpose' => 'recovery', 'code' => $this->code()])->assertOk();
        DB::table('sessions')->insert(['id' => 'another-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->withSession(['url.intended' => route('dashboard')])->postJson(route('password.update'), ['password' => 'NewSecure9!Pass', 'password_confirmation' => 'NewSecure9!Pass'])->assertOk()->assertSessionHas('url.intended', route('dashboard'));
        $this->assertDatabaseMissing('sessions', ['id' => 'another-device']);
    }

    public function test_cookie_sessions_are_rejected_before_creating_a_code_grant(): void
    {
        config(['session.driver' => 'cookie']);
        $this->postJson(route('password.email'), ['email' => 'test@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('auth_challenges', 0);
    }

    public function test_csrf_protection_and_session_rotation_are_preserved_during_immediate_registration(): void
    {
        $this->app->instance('env', 'local');
        $page = $this->get(route('register'))->assertOk();
        $xsrf = $page->getCookie('XSRF-TOKEN', false)->getValue();
        $this->postJson(route('register.store'), $this->details())->assertStatus(419);
        $before = session()->getId();
        $this->postJson(route('register.store'), $this->details(), ['X-XSRF-TOKEN' => $xsrf])->assertCreated()->assertJsonPath('next', 'authenticated');
        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticated();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('auth_challenges', 0);
        $this->postJson(route('password.email'), ['email' => 'test@example.com'])->assertStatus(419);
        $this->postJson(route('password.update'), ['password' => 'NewSecure9!Pass', 'password_confirmation' => 'NewSecure9!Pass'])->assertStatus(419);
        $this->patchJson(route('auth.registration.update'), $this->details())->assertStatus(419);
    }

    public function test_delivery_jobs_are_encrypted_at_rest_and_both_recovery_outcomes_are_queued(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $this->postJson(route('password.email'), ['email' => 'unknown@example.com'])->assertOk();
        Notification::assertNothingSent();
        $payloads = DB::table('jobs')->orderBy('id')->pluck('payload');
        $this->assertCount(2, $payloads);
        $jobs = $payloads->map(function (string $payload) {
            $encoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command'];
            $this->assertStringNotContainsString('challengeId', $encoded);
            $job = unserialize(Crypt::decrypt($encoded));
            $this->assertInstanceOf(DeliverAuthCode::class, $job);

            return $job;
        });
        $jobs[0]->handle();
        $code = $this->code();
        foreach ($payloads as $payload) {
            $this->assertStringNotContainsString($code, $payload);
        }
        $jobs[1]->handle();
        Notification::assertSentOnDemandOnce(AuthVerificationCode::class);
    }

    public function test_a_delayed_job_does_not_send_an_expired_code(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize(Crypt::decrypt($payload['data']['command']));
        $this->travelTo(AuthChallenge::sole()->expires_at);
        $job->handle();
        Notification::assertNothingSent();
    }

    public function test_public_environments_require_a_real_asynchronous_queue(): void
    {
        $this->app->instance('env', 'staging');
        config(['queue.default' => 'sync']);
        $this->withSession(['_token' => 'queue-config-check'])->postJson(route('password.email'), ['email' => 'anyone@example.com', '_token' => 'queue-config-check'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('auth_challenges', 0);
    }

    public function test_password_length_and_real_symbol_rules_match_unicode_ui_validation(): void
    {
        foreach (['Letters9 ', 'LongPassword12!xx'] as $password) {
            $this->postJson(route('register.store'), [...$this->details(), 'password' => $password, 'password_confirmation' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $password = 'Abcdefghijklm1🫒!';
        $this->postJson(route('register.store'), [...$this->details(), 'password' => $password, 'password_confirmation' => $password])->assertCreated();
    }

    public function test_the_full_lockout_starts_at_the_fifth_failure_not_the_first(): void
    {
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->travel(299)->seconds();
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->travel(2)->seconds();
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests()->assertHeader('Retry-After', '298');
        $this->travel(298)->seconds();
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_malformed_registration_shapes_are_rejected_before_fortify_canonicalization(): void
    {
        $this->postJson(route('register.store'), [...$this->details(), 'email' => []])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(route('register.store'), ['name' => ['unexpected'], 'email' => 'test@example.com', 'password' => 'Secure9!Pass'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('users', 0);
    }
}
