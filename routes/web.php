<?php

use App\Http\Controllers\ConsolidatedPreOrderController;
use App\Http\Controllers\GroupPreOrderSubmissionController;
use App\Http\Controllers\GuestDishSelectionController;
use App\Http\Controllers\GuestInvitationController;
use App\Http\Controllers\RestaurantController;
use App\Http\Middleware\AuthenticateGuestInvitation;
use App\Http\Middleware\InvitationSecurityHeaders;
use Illuminate\Support\Facades\Route;

Route::get('/', [RestaurantController::class, 'index'])->name('home');
Route::get('restaurants/{restaurant:slug}', [RestaurantController::class, 'show'])->name('restaurants.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::get('reservations/{reservation}/pre-order/summary', [ConsolidatedPreOrderController::class, 'show'])
    ->middleware('auth')
    ->name('reservations.pre-order.summary');

Route::post('reservations/{reservation}/pre-order/submit', [GroupPreOrderSubmissionController::class, 'store'])
    ->middleware(['auth', 'throttle:pre-order-submission'])
    ->name('reservations.pre-order.submit');

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

Route::get('invitations/{token}/selections', [GuestDishSelectionController::class, 'show'])
    ->withoutMiddleware('web')
    ->middleware([InvitationSecurityHeaders::class, 'throttle:invitation-access', AuthenticateGuestInvitation::class])
    ->name('invitations.selections.show');

Route::put('invitations/{token}/selections', [GuestDishSelectionController::class, 'update'])
    ->withoutMiddleware('web')
    ->middleware([InvitationSecurityHeaders::class, 'throttle:guest-selections', AuthenticateGuestInvitation::class])
    ->name('invitations.selections.update');
