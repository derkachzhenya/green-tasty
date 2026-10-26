<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 20)->default('waiter');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['restaurant_id', 'user_id']);
        });
        Schema::table('pre_orders', function (Blueprint $table): void {
            $table->unsignedInteger('review_revision')->default(1);
            $table->unsignedInteger('reviewed_revision')->default(0);
            $table->timestamp('review_submitted_at')->nullable();
        });
        Schema::table('pre_order_items', function (Blueprint $table): void {
            $table->string('restaurant_decision', 20)->default('pending');
            $table->timestamp('review_published_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
        });
        Schema::create('pre_order_review_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pre_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['pre_order_id', 'revision']);
        });
        Schema::create('reservation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('audience', 20);
            $table->string('kind', 40);
            $table->unsignedInteger('revision');
            $table->string('event_key', 64)->unique();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_events');
        Schema::dropIfExists('pre_order_review_rounds');
        Schema::table('pre_order_items', fn (Blueprint $table) => $table->dropColumn(['restaurant_decision', 'review_published_at', 'superseded_at']));
        Schema::table('pre_orders', fn (Blueprint $table) => $table->dropColumn(['review_revision', 'reviewed_revision', 'review_submitted_at']));
        Schema::dropIfExists('restaurant_staff');
    }
};
