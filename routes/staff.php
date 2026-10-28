<?php

use App\Http\Controllers\RejectedItemCorrectionController;
use App\Http\Controllers\StaffAccessController;
use App\Http\Controllers\StaffLoginController;
use App\Http\Controllers\StaffMenuController;
use App\Http\Controllers\StaffRegistrationController;
use App\Http\Controllers\WaiterReservationController;
use App\Http\Middleware\AuthSecurityHeaders;
use Illuminate\Support\Facades\Route;

Route::get('staff', [StaffAccessController::class, 'show'])->middleware('cache.headers:private;no_store')->name('staff.access');
Route::get('staff/login', [StaffAccessController::class, 'login'])->middleware(AuthSecurityHeaders::class)->name('staff.login');
Route::post('staff/login', [StaffLoginController::class, 'store'])->middleware(['guest', AuthSecurityHeaders::class])->name('staff.login.store');
Route::post('staff/register', StaffRegistrationController::class)->middleware(['guest', AuthSecurityHeaders::class])->name('staff.register.store');
Route::get('staff/enter', [StaffAccessController::class, 'enter'])->middleware(['auth', 'verified', 'cache.headers:private;no_store'])->name('staff.entry');

Route::middleware(['auth', 'verified', 'cache.headers:private;no_store'])->group(function (): void {
    Route::get('staff/restaurants/{restaurant}/menu', StaffMenuController::class)->name('staff.menu');
    Route::post('reservations/{reservation}/restaurant-review/items/{item}/replace', RejectedItemCorrectionController::class)
        ->whereNumber('item')->middleware('throttle:guest-selections')->name('reservations.review.replace');
    Route::prefix('staff/restaurants/{restaurant}/reservations')->scopeBindings()->group(function (): void {
        Route::get('/', [WaiterReservationController::class, 'index'])->name('staff.reservations.index');
        Route::post('/', [WaiterReservationController::class, 'create'])->middleware('throttle:booking-create')->name('staff.reservations.create');
        Route::patch('{reservation}', [WaiterReservationController::class, 'update'])->middleware('throttle:booking-create')->name('staff.reservations.update');
        Route::post('{reservation}/cancel', [WaiterReservationController::class, 'cancel'])->middleware('throttle:booking-create')->name('staff.reservations.cancel');
        Route::get('{reservation}/review', [WaiterReservationController::class, 'review'])->name('staff.review.show');
        Route::patch('{reservation}/review/items/{item}', [WaiterReservationController::class, 'decide'])->whereNumber('item')->middleware('throttle:guest-selections')->name('staff.review.decision');
        Route::post('{reservation}/review/submit', [WaiterReservationController::class, 'submit'])->middleware('throttle:pre-order-submission')->name('staff.review.submit');
        Route::get('{reservation}/order', [WaiterReservationController::class, 'order'])->name('staff.order.show');
        Route::put('{reservation}/order', [WaiterReservationController::class, 'saveOrder'])->middleware('throttle:guest-selections')->name('staff.order.save');
    });
});
