<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\AuthVerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_request_screen_can_be_rendered(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    public function test_recovery_code_can_be_requested_and_used_to_reset_the_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk()->assertJsonPath('next', 'verify');
        Notification::assertSentOnDemand(AuthVerificationCode::class, function (AuthVerificationCode $notification): bool {
            $this->assertSame('recovery', $notification->purpose);
            $this->postJson(route('auth.code.verify'), ['purpose' => 'recovery', 'code' => $notification->code])->assertOk();

            return true;
        });
        $this->get(route('auth.reset'))->assertOk();
        $this->post(route('password.update'), ['password' => 'NewSecure9!Pass', 'password_confirmation' => 'NewSecure9!Pass'])
            ->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('NewSecure9!Pass', $user->fresh()->password));
    }

    public function test_password_cannot_be_reset_with_an_unverified_client_supplied_token(): void
    {
        $user = User::factory()->create();
        $this->postJson(route('password.update'), ['token' => 'invalid-token', 'email' => $user->email, 'password' => 'NewSecure9!Pass', 'password_confirmation' => 'NewSecure9!Pass'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
