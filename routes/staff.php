<?php

use App\Http\Controllers\RejectedItemCorrectionController;
use App\Http\Controllers\WaiterReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'cache.headers:private;no_store'])->group(function (): void {
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
    });
});
