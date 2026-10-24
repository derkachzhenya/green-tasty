<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthCodeRequest;
use App\Http\Requests\AuthRecoveryRequest;
use App\Models\User;
use App\Services\AuthCodeService;
use App\Services\RestaurantListingService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthFlowController extends Controller
{
    public function __construct(private AuthCodeService $codes) {}

    public function show(Request $request, string $step = 'login'): Response|RedirectResponse
    {
        $purpose = $step === 'reset' ? AuthCodeService::RECOVERY : $request->session()->get('auth.code_purpose', AuthCodeService::REGISTRATION);
        $context = $this->codes->context($request, is_string($purpose) ? $purpose : AuthCodeService::REGISTRATION);
        if (($step === 'verify' && $context === null) || ($step === 'reset' && ($context === null || ! $context['verified']))) {
            return redirect()->route($step === 'reset' ? 'password.request' : 'login');
        }
        if ($step === 'verify' && $context['purpose'] === AuthCodeService::RECOVERY && $context['verified']) {
            return redirect()->route('auth.reset');
        }

        return $this->render($step, $context);
    }

    public function verificationNotice(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        return $this->render('verify', ['purpose' => AuthCodeService::REGISTRATION, 'email' => $user->email, 'resend_after' => 0, 'verified' => false], true);
    }

    public function beginVerification(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_if($user->hasVerifiedEmail(), 403);
        // Only this CSRF-protected POST may send mail and end an unverified session.
        app(StatefulGuard::class)->logout();
        $request->session()->regenerate();
        $existing = $this->codes->context($request, AuthCodeService::REGISTRATION);
        $context = $existing !== null && $existing['email'] === $user->email ? $existing
            : $this->codes->start($request, AuthCodeService::REGISTRATION, $user->email, $user);

        return $request->expectsJson() ? response()->json(['next' => 'verify', 'challenge' => $context]) : redirect()->route('auth.verify');
    }

    /** @param array{purpose: string, email: string, resend_after: int, verified: bool}|null $context */
    private function render(string $step, ?array $context, bool $verificationRequired = false): Response
    {
        return Inertia::render('auth/Flow', [
            'initialStep' => $step, 'challenge' => $context, 'verificationRequired' => $verificationRequired,
            'restaurants' => app(RestaurantListingService::class)->listActive(),
        ]);
    }

    public function recover(AuthRecoveryRequest $request): JsonResponse|RedirectResponse
    {
        $email = mb_strtolower(trim($request->string('email')->toString()));
        $user = User::where('email', $email)->first();
        // A collation-equivalent spelling is not the registered delivery address.
        $context = $this->codes->start($request, AuthCodeService::RECOVERY, $email, $user?->email === $email ? $user : null);

        return $request->expectsJson() ? response()->json(['next' => 'verify', 'challenge' => $context]) : redirect()->route('auth.verify');
    }

    public function verify(AuthCodeRequest $request): JsonResponse
    {
        $purpose = $request->string('purpose')->toString();
        $this->codes->verify($request, $purpose, $request->string('code')->toString());

        return response()->json(['next' => $purpose === AuthCodeService::REGISTRATION ? 'login' : 'reset']);
    }

    public function resend(AuthCodeRequest $request): JsonResponse
    {
        return response()->json(['challenge' => $this->codes->resend($request, $request->string('purpose')->toString())]);
    }

    public function reviseRegistration(Request $request): JsonResponse
    {
        return response()->json(['next' => 'verify', 'challenge' => $this->codes->reviseRegistration($request, $request->only([
            'first_name', 'last_name', 'email', 'password', 'password_confirmation',
        ]))]);
    }

    public function reset(AuthRecoveryRequest $request): JsonResponse|RedirectResponse
    {
        $this->codes->reset($request, [
            'password' => $request->string('password')->toString(),
            'password_confirmation' => $request->string('password_confirmation')->toString(),
        ]);

        return $request->expectsJson() ? response()->json(['next' => 'login']) : redirect()->route('login');
    }

    public function legacyReset(Request $request): RedirectResponse
    {
        $email = $request->input('email');
        $valid = is_string($email) && strlen($email) <= 255
            && $this->codes->acceptLegacyLink($request, (string) $request->route('token'), $email);
        if ($valid) {
            $request->session()->regenerate(true);
        }

        return redirect()->route($valid ? 'auth.reset' : 'password.request');
    }
}
