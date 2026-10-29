<?php

use App\Services\PreOrderCutoffService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pre-orders:expire', function (PreOrderCutoffService $cutoff) {
    $count = 0;
    $cutoff->due()->select('reservations.*')->chunkById(100, function ($reservations) use ($cutoff, &$count) {
        foreach ($reservations as $reservation) {
            $count += $cutoff->expire($reservation);
        }
    });
    $this->info("Cancelled {$count} unconfirmed pre-order items.");
})->purpose('Cancel unconfirmed selections 30 minutes before reservations');

Schedule::command('pre-orders:expire')->everyMinute()->withoutOverlapping();
