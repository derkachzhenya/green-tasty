<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_order_customer_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pre_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 40);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['pre_order_id', 'revision'], 'pre_order_customer_revision_unique');
        });
        Schema::table('reservations', function (Blueprint $table): void {
            $table->unsignedBigInteger('pre_order_seen_event_id')->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('pre_order_customer_revisions')->exists()) {
            throw new RuntimeException('Customer revision history exists. Keep the schema and use a compatible application version.');
        }
        Schema::dropIfExists('pre_order_customer_revisions');
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('pre_order_seen_event_id'));
    }
};
