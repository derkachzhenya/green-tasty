<?php

use App\Http\Controllers\AuthFlowController;
use App\Http\Middleware\AuthSecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('email/verify', [AuthFlowController::class, 'verificationNotice'])
    ->middleware(['auth', AuthSecurityHeaders::class, 'throttle:auth-codes'])->name('verification.notice');
Route::post('email/verification-notification', [AuthFlowController::class, 'beginVerification'])
    ->middleware(['auth', AuthSecurityHeaders::class, 'throttle:auth-codes'])->name('verification.send');

Route::middleware(['guest', AuthSecurityHeaders::class, 'throttle:auth-codes'])->group(function (): void {
    Route::patch('auth/registration', [AuthFlowController::class, 'reviseRegistration'])->name('auth.registration.update');
    // Keep Fortify's public path/name conventions; only email-code delivery is adapted.
    Route::post('forgot-password', [AuthFlowController::class, 'recover'])->name('password.email');
    Route::post('reset-password', [AuthFlowController::class, 'reset'])->name('password.update');
    Route::post('auth/code/verify', [AuthFlowController::class, 'verify'])->name('auth.code.verify');
    Route::post('auth/code/resend', [AuthFlowController::class, 'resend'])->name('auth.code.resend');
    Route::get('verify-email', fn (Request $request) => app(AuthFlowController::class)->show($request, 'verify'))->name('auth.verify');
    Route::get('reset-password', fn (Request $request) => app(AuthFlowController::class)->show($request, 'reset'))->name('auth.reset');
});
