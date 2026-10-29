<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_order_items', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::table('guest_pre_order_rounds', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pre_order_items', fn (Blueprint $table) => $table->dropColumn('cancelled_at'));
        Schema::table('guest_pre_order_rounds', fn (Blueprint $table) => $table->dropColumn('cancelled_at'));
    }
};
