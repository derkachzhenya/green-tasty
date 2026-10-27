<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            // Existing reservations keep their original two-hour interval.
            $table->unsignedSmallInteger('duration_minutes')->default(120);
        });
    }

    public function down(): void
    {
        if (DB::table('reservations')->where('duration_minutes', '!=', 120)->exists()) {
            throw new RuntimeException('Cannot remove reservation durations while non-default intervals exist. Keep the column and restore a compatible application version.');
        }
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn('duration_minutes');
        });
    }
};
