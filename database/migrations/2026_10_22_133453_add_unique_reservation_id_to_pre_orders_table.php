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
        // InnoDB may discard its automatic foreign-key index when the unique index is added.
        $hasSupportingIndex = collect(Schema::getIndexes('pre_orders'))->contains(
            fn (array $index): bool => $index['name'] !== 'pre_orders_reservation_id_unique'
                && ($index['columns'][0] ?? null) === 'reservation_id'
        );

        if (! $hasSupportingIndex) {
            Schema::table('pre_orders', function (Blueprint $table) {
                $table->index('reservation_id', 'pre_orders_reservation_id_index');
            });
        }

        Schema::table('pre_orders', function (Blueprint $table) {
            $table->dropUnique(
                'pre_orders_reservation_id_unique'
            );
        });
    }
};
