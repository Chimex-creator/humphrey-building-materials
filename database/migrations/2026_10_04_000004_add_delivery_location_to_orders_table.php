<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phases 16–19 — snapshot the delivery location and fee decision onto the
 * order itself.
 *
 * Historical orders must keep showing the price that was agreed at checkout
 * even if an admin later retunes zones, renames an area or disables a state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_state_id')->nullable()->after('delivery_fee_status')
                ->constrained('delivery_states')->nullOnDelete();
            $table->foreignId('delivery_area_id')->nullable()->after('delivery_state_id')
                ->constrained('delivery_areas')->nullOnDelete();
            $table->foreignId('delivery_zone_id')->nullable()->after('delivery_area_id')
                ->constrained('delivery_zones')->nullOnDelete();

            // Snapshot names — what the customer actually chose.
            $table->string('delivery_state_name', 80)->nullable()->after('delivery_zone_id');
            $table->string('delivery_area_name', 80)->nullable()->after('delivery_state_name');
            $table->string('delivery_zone_name', 80)->nullable()->after('delivery_area_name');

            // Where the number came from: zone | state | manual | none.
            $table->string('delivery_fee_source', 30)->nullable()->after('delivery_zone_name');
            $table->string('delivery_fee_note', 500)->nullable()->after('delivery_fee_source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_state_id');
            $table->dropConstrainedForeignId('delivery_area_id');
            $table->dropConstrainedForeignId('delivery_zone_id');
            $table->dropColumn([
                'delivery_state_name',
                'delivery_area_name',
                'delivery_zone_name',
                'delivery_fee_source',
                'delivery_fee_note',
            ]);
        });
    }
};
