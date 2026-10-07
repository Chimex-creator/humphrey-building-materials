<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 7 (completion) - delivery records (Master prompt, section 27).
 *
 * One row per DELIVERY order (pickup orders have no delivery record).
 *
 * The address and the requested date live on the order already - we do not
 * copy them here, otherwise confirming a new address in one place would
 * silently disagree with the other. This table only holds the extra facts a
 * delivery needs: who is taking it, when we promised, which trip it is on,
 * and what went wrong if it failed.
 *
 * `status` is the delivery's own status (Pending / Confirmed / Out for
 * Delivery / Delivered / Failed-Rescheduled). Order status stays the
 * customer-facing lifecycle; the two are kept in step by the controllers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('delivery_trips')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->date('confirmed_date')->nullable(); // the date we actually committed to
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
