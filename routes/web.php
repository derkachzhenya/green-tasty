<?php

use App\Http\Controllers\ConsolidatedPreOrderController;
use App\Http\Controllers\GuestInvitationController;
use App\Http\Middleware\InvitationSecurityHeaders;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::get('reservations/{reservation}/pre-order/summary', [ConsolidatedPreOrderController::class, 'show'])
    ->middleware('auth')
    ->name('reservations.pre-order.summary');

require __DIR__.'/settings.php';

Route::middleware(['auth', InvitationSecurityHeaders::class, 'throttle:invitation-management'])
    ->scopeBindings()
    ->group(function () {
        Route::post('reservations/{reservation}/invitations', [GuestInvitationController::class, 'store'])
            ->name('reservations.invitations.store');
        Route::post('reservations/{reservation}/invitations/{guest}/regenerate', [GuestInvitationController::class, 'regenerate'])
            ->name('reservations.invitations.regenerate');
        Route::delete('reservations/{reservation}/invitations/{guest}', [GuestInvitationController::class, 'destroy'])
            ->name('reservations.invitations.destroy');
    });

// Bearer URLs must never be persisted as the session's previous URL.
Route::get('invitations/{token}', [GuestInvitationController::class, 'show'])
    ->withoutMiddleware('web')
    ->middleware([InvitationSecurityHeaders::class, 'throttle:invitation-access'])
    ->name('invitations.show');
