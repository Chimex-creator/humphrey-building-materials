<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Trips - full management (Master Scope Update §18-20).
 *
 * Adds the fields a real dispatch board needs on top of the original
 * "name + day" grouping: a printable trip reference, the route/area the
 * trip covers, and a lifecycle status so staff can see what is planned,
 * running, done or called off. Still manual - no GPS, no routing engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_trips', function (Blueprint $table) {
            $table->string('reference', 40)->unique()->after('id');
            $table->string('route_area', 160)->nullable()->after('trip_date');
            $table->string('status', 30)->default('planned')->after('route_area');
        });

        // Existing trips get a reference so the unique index is never empty.
        DB::table('delivery_trips')
            ->orderBy('id')
            ->get()
            ->each(function (object $trip, int $index) {
                DB::table('delivery_trips')
                    ->where('id', $trip->id)
                    ->update(['reference' => sprintf('TRP-%s-%04d', date('Y'), $index + 1)]);
            });
    }

    public function down(): void
    {
        Schema::table('delivery_trips', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'route_area', 'status']);
        });
    }
};
