<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table): void {
            $table->timestamp('name_confirmed_at')->nullable();
            $table->timestamp('pre_order_submitted_at')->nullable();
            $table->unsignedInteger('selection_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table): void {
            $table->dropColumn(['name_confirmed_at', 'pre_order_submitted_at', 'selection_version']);
        });
    }
};
