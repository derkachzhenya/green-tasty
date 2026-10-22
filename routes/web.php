<?php

use App\Http\Controllers\ConsolidatedPreOrderController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::get('reservations/{reservation}/pre-order/summary', [ConsolidatedPreOrderController::class, 'show'])
    ->middleware('auth')
    ->name('reservations.pre-order.summary');

require __DIR__.'/settings.php';
