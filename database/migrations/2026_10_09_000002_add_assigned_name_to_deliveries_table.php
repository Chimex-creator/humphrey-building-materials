<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 — optional free-text name for whoever is taking a delivery.
 *
 * Complements (does not replace) the assigned_to staff FK: external
 * couriers with no website account can be named without creating a fake
 * user. Existing assigned_to values and history stay untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('assigned_name', 100)->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('assigned_name');
        });
    }
};
