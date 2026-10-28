<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_service_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->timestamps();
        });
        Schema::create('restaurant_service_order_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_order_id')->constrained('restaurant_service_orders')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('items');
            $table->decimal('total', 14, 2);
            $table->timestamp('created_at');
            $table->unique(['service_order_id', 'revision'], 'service_order_revision_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('restaurant_service_orders')->exists()) {
            throw new RuntimeException('Service order history must be archived before removing these tables.');
        }
        Schema::dropIfExists('restaurant_service_order_revisions');
        Schema::dropIfExists('restaurant_service_orders');
    }
};
