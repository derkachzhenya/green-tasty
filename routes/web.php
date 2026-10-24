<?php

use App\Http\Controllers\ConsolidatedPreOrderController;
use App\Http\Controllers\GroupPreOrderSubmissionController;
use App\Http\Controllers\GuestDishSelectionController;
use App\Http\Controllers\GuestInvitationController;
use App\Http\Controllers\GuestMenuController;
use App\Http\Controllers\MyReservationsController;
use App\Http\Controllers\ReservationDashboardController;
use App\Http\Controllers\RestaurantBookingController;
use App\Http\Controllers\RestaurantController;
use App\Http\Middleware\AuthenticateGuestInvitation;
use App\Http\Middleware\InvitationSecurityHeaders;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::get('/', [RestaurantController::class, 'index'])->name('home');
Route::get('restaurants/{restaurant:slug}', [RestaurantController::class, 'show'])->name('restaurants.show');

Route::middleware(['auth', 'verified', 'cache.headers:private;no_store'])->group(function () {
    Route::get('reservations/{reservation}', [ReservationDashboardController::class, 'show'])->name('reservations.show');
    Route::get('restaurants/{restaurant:slug}/book', [RestaurantBookingController::class, 'create'])->name('restaurants.bookings.create');
    Route::get('restaurants/{restaurant:slug}/availability', [RestaurantBookingController::class, 'availability'])->middleware('throttle:booking-availability')->name('restaurants.availability');
    Route::post('restaurants/{restaurant:slug}/book', [RestaurantBookingController::class, 'store'])->middleware('throttle:booking-create')->name('restaurants.bookings.store');
    Route::get('reservations/{reservation}/confirmation', [RestaurantBookingController::class, 'show'])->name('reservations.confirmation');
});

Route::get('dashboard', MyReservationsController::class)
    ->middleware(['auth', 'verified', 'cache.headers:private;no_store'])
    ->name('dashboard');

Route::get('reservations/{reservation}/pre-order/summary', [ConsolidatedPreOrderController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('reservations.pre-order.summary');

Route::post('reservations/{reservation}/pre-order/submit', [GroupPreOrderSubmissionController::class, 'store'])
    ->middleware(['auth', 'verified', 'throttle:pre-order-submission'])
    ->name('reservations.pre-order.submit');

require __DIR__.'/settings.php';

Route::middleware(['auth', 'verified', InvitationSecurityHeaders::class, 'throttle:invitation-management'])
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

Route::get('invitations/{token}/menu', GuestMenuController::class)
    ->withoutMiddleware('web')
    ->middleware([InvitationSecurityHeaders::class, 'throttle:invitation-access', AuthenticateGuestInvitation::class])
    ->name('invitations.menu');

Route::put('invitations/{token}/selections', [GuestDishSelectionController::class, 'update'])
    ->withoutMiddleware('web')
    ->middleware([InvitationSecurityHeaders::class, 'throttle:guest-selections', AuthenticateGuestInvitation::class])
    ->name('invitations.selections.update');
