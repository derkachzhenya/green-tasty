<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_order_items', function (Blueprint $table): void {
            $table->unsignedInteger('selection_round')->default(0);
            $table->unique(['pre_order_id', 'reservation_guest_id', 'menu_item_id', 'selection_round'], 'pre_order_guest_item_round_unique');
            $table->dropUnique('pre_order_guest_item_unique');
        });
        Schema::create('guest_pre_order_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_guest_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('items');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['reservation_guest_id', 'number']);
        });
    }

    public function down(): void
    {
        if (DB::table('guest_pre_order_rounds')->exists() || DB::table('pre_order_items')->where('selection_round', '!=', 0)->exists()) {
            throw new RuntimeException('Additional guest pre-orders exist. Keep their history and use a compatible application version.');
        }
        Schema::dropIfExists('guest_pre_order_rounds');
        Schema::table('pre_order_items', function (Blueprint $table): void {
            $table->unique(['pre_order_id', 'reservation_guest_id', 'menu_item_id'], 'pre_order_guest_item_unique');
            $table->dropUnique('pre_order_guest_item_round_unique');
            $table->dropColumn('selection_round');
        });
    }
};
