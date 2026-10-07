<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — Customer Care / support records.
 *
 * A structured complaint/enquiry workflow: the customer describes the
 * problem once, staff respond and move it through
 * open → in_progress → resolved → closed. The record represents the real
 * business state of the complaint — sending a notification never resolves
 * it on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();          // e.g. SUP-2026-000001
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 30)->nullable();
            $table->string('category', 40);                 // see SupportTicket::CATEGORIES
            $table->string('subject');
            $table->text('description');
            $table->string('status', 20)->default('open');  // open | in_progress | resolved | closed
            $table->text('staff_response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
