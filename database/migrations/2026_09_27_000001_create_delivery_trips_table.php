<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 7 (completion) - delivery trips (Master prompt, section 27).
 *
 * A trip is a simple, manual grouping: "Ikeja run - Tuesday". Staff decide
 * which deliveries go on it. It is deliberately loose - no routing, no
 * driver accounts, just a name and a day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_trips', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('trip_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_trips');
    }
};
