<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phases 8–13 — admin-configurable delivery geography.
 *
 *  - delivery_zones  : named fee bands (the most specific price wins)
 *  - delivery_states : every state/territory we might deliver to,
 *                      with its own default fee and on/off switch
 *  - delivery_areas  : places inside a state (Mararaba, Nyanya, Lafia...)
 *                      that can be mapped to at most one zone
 *
 * An inactive state/area/zone means "we do not deliver there" and the
 * checkout refuses the order instead of guessing a price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('fee', 12, 2)->default(0);
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamps();
        });

        Schema::create('delivery_states', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('status', 20)->default('active'); // active | inactive
            $table->decimal('default_fee', 12, 2)->nullable(); // null = not serviceable
            $table->timestamps();
        });

        Schema::create('delivery_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_state_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('active'); // active | inactive
            // At most one zone per area; unmapped areas fall back to the state fee.
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['delivery_state_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_areas');
        Schema::dropIfExists('delivery_states');
        Schema::dropIfExists('delivery_zones');
    }
};
