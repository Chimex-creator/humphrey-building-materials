<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL SPEC §22 / §33 — Delivery Trips are removed completely.
 *
 * Delivery records stay (one per delivery order, with their own status
 * workflow); only the trip grouping entity, its column on deliveries and
 * its routes/views go away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trip_id');
        });

        Schema::dropIfExists('delivery_trips');
    }

    public function down(): void
    {
        Schema::create('delivery_trips', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('trip_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('trip_id')->nullable()->after('order_id')->constrained('delivery_trips')->nullOnDelete();
        });
    }
};
