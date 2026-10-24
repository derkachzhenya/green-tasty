<?php

namespace App\Services;

use App\Concerns\AuthRegistrationRules;
use App\Jobs\DeliverAuthCode;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Support\AuthMailConfiguration;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\CompletePasswordReset;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** @phpstan-type CodeContext array{purpose: string, email: string, resend_after: int, verified: bool} */
class AuthCodeService
{
    use AuthRegistrationRules;

    public const REGISTRATION = 'registration';

    public const RECOVERY = 'recovery';

    /** @return CodeContext */
    public function start(Request $request, string $purpose, string $email, ?User $user): array
    {
        $this->assertDeliveryIsSafe();
        $email = mb_strtolower(trim($email));
        $challenge = DB::transaction(function () use ($purpose, $email, $user): AuthChallenge {
            $row = $this->lockChallenge($purpose, $email);
            if ($row->resend_at->isFuture()) {
                throw new TooManyRequestsHttpException(max(1, (int) ceil(now()->diffInSeconds($row->resend_at))), 'Please wait before requesting another code.');
            }

            $code = strtoupper(bin2hex(random_bytes(8)));
            $row->forceFill([
                'user_id' => $user?->id,
                'generation' => bin2hex(random_bytes(16)),
                'code_hash' => $this->digest($purpose, $code),
                'password_fingerprint' => $user === null ? null : $this->fingerprint($user),
                'attempts' => 0, 'expires_at' => now()->addMinutes(10),
                'resend_at' => now()->addSeconds(60), 'verified_at' => null, 'consumed_at' => null,
            ])->save();

            // Queue the same encrypted job for known and unknown recovery addresses.
            // SMTP latency and outages cannot reveal account existence in the HTTP response.
            DeliverAuthCode::dispatch($row->id, $row->generation, $code);

            return $row;
        }, 3);

        $request->session()->put('auth.codes.'.$purpose, ['id' => $challenge->id, 'generation' => $challenge->generation]);
        $request->session()->put('auth.code_purpose', $purpose);

        return $this->metadata($challenge);
    }

    /** @return CodeContext */
    public function resend(Request $request, string $purpose): array
    {
        return DB::transaction(function () use ($request, $purpose): array {
            $row = $this->current($request, $purpose, true);
            if ($row === null || $row->consumed_at !== null || $row->verified_at !== null) {
                $this->invalid();
            }
            $user = $this->boundUser($row, true);
            if (($row->user_id !== null && $user === null) || ($purpose === self::REGISTRATION && ($user === null || $user->hasVerifiedEmail()))) {
                $this->invalid();
            }

            return $this->start($request, $purpose, $row->email, $user);
        }, 3);
    }

    public function verify(Request $request, string $purpose, #[SensitiveParameter] string $code): void
    {
        $verifiedUser = null;
        $grant = null;
        $success = DB::transaction(function () use ($request, $purpose, $code, &$verifiedUser, &$grant): bool {
            $row = $this->current($request, $purpose, true);
            if ($row === null || ! $row->expires_at->isFuture() || $row->consumed_at !== null || $row->verified_at !== null || $row->attempts >= 5) {
                return false;
            }
            $row->attempts++;
            $row->save();
            if ($row->user_id === null || $row->code_hash === null || ! hash_equals($row->code_hash, $this->digest($purpose, $code))) {
                return false;
            }
            $user = $this->boundUser($row, true);
            if ($user === null) {
                return false;
            }
            $row->code_hash = null;
            $row->verified_at = now();
            if ($purpose === self::REGISTRATION) {
                $user->forceFill(['email_verified_at' => now()])->save();
                $row->consumed_at = now();
                $verifiedUser = $user;
            } else {
                // Pre-verification session data must not become a reset capability.
                $row->generation = bin2hex(random_bytes(16));
                $grant = ['id' => $row->id, 'generation' => $row->generation];
            }
            $row->save();

            return true;
        }, 3);

        // Throw after committing failed-attempt counters.
        if (! $success) {
            $this->invalid();
        }
        $request->session()->regenerate(true);
        if ($grant !== null) {
            $request->session()->put('auth.codes.'.self::RECOVERY, $grant);
        }
        if ($verifiedUser instanceof User) {
            $request->session()->forget('auth.codes.'.self::REGISTRATION);
            event(new Verified($verifiedUser));
        }
    }

    /** @param array<string, string> $passwords */
    public function reset(Request $request, #[SensitiveParameter] array $passwords): void
    {
        $success = DB::transaction(function () use ($request, $passwords): bool {
            $row = $this->current($request, self::RECOVERY, true);
            if ($row === null || $row->verified_at === null || $row->consumed_at !== null || ! $row->expires_at->isFuture() || $row->user_id === null) {
                return false;
            }
            $user = $this->boundUser($row, true);
            if ($user === null) {
                return false;
            }

            // Fortify's broker token exists only in server memory. Keep its reset action,
            // remember-token rotation and PasswordReset event instead of duplicating them.
            $broker = Password::broker(config('fortify.passwords'));
            $token = $broker->createToken($user);
            $status = $broker->reset([...$passwords, 'email' => $row->email, 'token' => $token], function (User $user, #[SensitiveParameter] string $password) use ($passwords): void {
                app(ResetsUserPasswords::class)->reset($user, $passwords);
                app(CompletePasswordReset::class)(app(StatefulGuard::class), $user);
            });
            if ($status !== Password::PASSWORD_RESET) {
                return false;
            }
            $row->forceFill(['consumed_at' => now(), 'password_fingerprint' => null])->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            return true;
        }, 3);
        if (! $success) {
            $this->invalid();
        }
        $intended = $request->session()->get('url.intended');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if (is_string($intended)) {
            $request->session()->put('url.intended', $intended);
        }
    }

    /** @return CodeContext|null */
    public function context(Request $request, string $purpose): ?array
    {
        $row = $this->current($request, $purpose);

        return $row === null || $row->consumed_at !== null || ($row->user_id !== null && $this->boundUser($row) === null) ? null : $this->metadata($row);
    }

    /** @param array<string, string> $input
     * @return CodeContext
     */
    public function reviseRegistration(Request $request, #[SensitiveParameter] array $input): array
    {
        return DB::transaction(function () use ($request, $input): array {
            $row = $this->current($request, self::REGISTRATION, true);
            if ($row === null || $row->consumed_at !== null || ! $row->expires_at->isFuture() || $row->user_id === null) {
                $this->invalid();
            }
            $user = $this->boundUser($row, true);
            if ($user === null || $user->hasVerifiedEmail()) {
                $this->invalid();
            }
            Validator::make($input, $this->registrationRules($user->id))->validate();
            // The Back action may correct pending details, but cannot bypass resend limits.
            if ($row->resend_at->isFuture()) {
                throw new TooManyRequestsHttpException(max(1, (int) ceil(now()->diffInSeconds($row->resend_at))));
            }
            $row->forceFill(['consumed_at' => now(), 'code_hash' => null])->save();
            $user->forceFill(['name' => $input['first_name'].' '.$input['last_name'], 'email' => mb_strtolower($input['email']), 'password' => $input['password']])->save();

            return $this->start($request, self::REGISTRATION, $user->email, $user);
        }, 3);
    }

    /** Already-issued Fortify email links are exchanged for a session grant and clean URL. */
    public function acceptLegacyLink(Request $request, #[SensitiveParameter] string $token, string $email): bool
    {
        $user = User::where('email', mb_strtolower($email))->first();
        if ($user === null || ! Password::broker(config('fortify.passwords'))->tokenExists($user, $token)) {
            return false;
        }

        // Consume the legacy token now, so the URL cannot establish another session grant.
        return DB::transaction(function () use ($request, $user, $token): bool {
            $row = $this->lockChallenge(self::RECOVERY, $user->email);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $broker = Password::broker(config('fortify.passwords'));
            if (! $broker->tokenExists($user, $token)) {
                return false;
            }
            $broker->deleteToken($user);
            $row->forceFill([
                'email' => $user->email, 'user_id' => $user->id, 'generation' => bin2hex(random_bytes(16)),
                'code_hash' => null, 'password_fingerprint' => $this->fingerprint($user), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'resend_at' => now()->addMinute(),
                'verified_at' => now(), 'consumed_at' => null,
            ])->save();
            $request->session()->put('auth.codes.'.self::RECOVERY, ['id' => $row->id, 'generation' => $row->generation]);

            return true;
        }, 3);
    }

    private function current(Request $request, string $purpose, bool $lock = false): ?AuthChallenge
    {
        $id = $request->session()->get('auth.codes.'.$purpose.'.id');
        $generation = $request->session()->get('auth.codes.'.$purpose.'.generation');
        if (! is_int($id) || ! is_string($generation)) {
            return null;
        }

        return AuthChallenge::query()->whereKey($id)->where('purpose', $purpose)->where('generation', $generation)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    private function lockChallenge(string $purpose, string $email): AuthChallenge
    {
        $identity = ['email_hash' => hash('sha256', $email), 'purpose' => $purpose];
        // A write followed by a locking current read also works after an older
        // MySQL repeatable-read snapshot; firstOrCreate's fallback read does not.
        AuthChallenge::query()->insertOrIgnore([
            ...$identity, 'email' => $email, 'generation' => bin2hex(random_bytes(16)),
            'expires_at' => now(), 'resend_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return AuthChallenge::where($identity)->lockForUpdate()->firstOrFail();
    }

    private function boundUser(AuthChallenge $row, bool $lock = false): ?User
    {
        if ($row->user_id === null) {
            return null;
        }
        $user = User::whereKey($row->user_id)->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $user !== null && $user->email === $row->email && hash_equals((string) $row->password_fingerprint, $this->fingerprint($user)) ? $user : null;
    }

    /** @return CodeContext */
    private function metadata(AuthChallenge $row): array
    {
        return ['purpose' => $row->purpose, 'email' => $row->email,
            'resend_after' => max(0, (int) ceil(now()->diffInSeconds($row->resend_at))),
            'verified' => $row->verified_at !== null && $row->expires_at->isFuture()];
    }

    private function digest(string $purpose, #[SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $purpose.'|'.strtoupper(trim($code)), (string) config('app.key'));
    }

    private function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->password, (string) config('app.key'));
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['code' => 'The verification code is invalid or has expired. Request a new code.']);
    }

    private function assertDeliveryIsSafe(): void
    {
        if (config('session.driver') === 'cookie') {
            throw ValidationException::withMessages(['email' => 'Server-side sessions are required for verification. Please contact the site administrator.']);
        }
        if (! app()->environment(['local', 'testing']) && ! in_array(config('queue.connections.'.config('queue.default').'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw ValidationException::withMessages(['email' => 'Asynchronous verification delivery is not configured. Please contact the site administrator.']);
        }
        app(AuthMailConfiguration::class)->assertSafe();
    }
}
