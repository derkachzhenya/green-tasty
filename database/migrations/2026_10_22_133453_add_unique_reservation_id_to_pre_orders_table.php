<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_orders', function (Blueprint $table) {
            $table->unique(
                'reservation_id',
                'pre_orders_reservation_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pre_orders', function (Blueprint $table) {
            $table->dropUnique(
                'pre_orders_reservation_id_unique'
            );
        });
    }
};
