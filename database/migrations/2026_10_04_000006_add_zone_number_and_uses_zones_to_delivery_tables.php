<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL SPEC §11 / §7 / §13 — explicit numeric zone order + zone-based states.
 *
 *  - delivery_zones.zone_number : display/sort order 1..10 (never alphabetical)
 *  - delivery_states.uses_zones : FCT and Nasarawa are priced exclusively
 *                                 through State → Area → Zone; the other 34
 *                                 states keep their state-level fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->unsignedSmallInteger('zone_number')->nullable()->unique()->after('name');
        });

        Schema::table('delivery_states', function (Blueprint $table) {
            $table->boolean('uses_zones')->default(false)->after('default_fee');
        });

        // Backfill zone numbers from the existing "Zone N" names.
        foreach (DB::table('delivery_zones')->orderBy('id')->get() as $zone) {
            if (preg_match('/(\d+)/', $zone->name, $matches)) {
                DB::table('delivery_zones')
                    ->where('id', $zone->id)
                    ->update(['zone_number' => (int) $matches[1]]);
            }
        }

        DB::table('delivery_states')
            ->whereIn('name', ['Federal Capital Territory', 'Nasarawa'])
            ->update(['uses_zones' => true]);
    }

    public function down(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            $table->dropUnique(['zone_number']);
            $table->dropColumn('zone_number');
        });

        Schema::table('delivery_states', function (Blueprint $table) {
            $table->dropColumn('uses_zones');
        });
    }
};
