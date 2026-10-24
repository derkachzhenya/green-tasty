<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Controllers\AuthFlowController;
use App\Http\Responses\AuthLockoutResponse;
use App\Http\Responses\AuthLoginResponse;
use App\Http\Responses\AuthRegisterResponse;
use App\Support\FiveMinuteLoginRateLimiter;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LockoutResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginRateLimiter::class, FiveMinuteLoginRateLimiter::class);
        $this->app->singleton(LoginResponse::class, AuthLoginResponse::class);
        $this->app->singleton(RegisterResponse::class, AuthRegisterResponse::class);
        $this->app->singleton(LockoutResponse::class, AuthLockoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A new sign-in must not reuse a previous account's history decryption key.
        Event::listen(Login::class, fn () => Inertia::clearHistory());

        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => app(AuthFlowController::class)->show($request, 'login'));
        Fortify::registerView(fn (Request $request) => app(AuthFlowController::class)->show($request, 'register'));
        Fortify::requestPasswordResetLinkView(fn (Request $request) => app(AuthFlowController::class)->show($request, 'forgot'));
        Fortify::resetPasswordView(fn (Request $request) => app(AuthFlowController::class)->legacyReset($request));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {

        RateLimiter::for('auth-codes', fn (Request $request) => Limit::perMinute(20)->by('auth-codes:'.$request->ip()));

    }
}
