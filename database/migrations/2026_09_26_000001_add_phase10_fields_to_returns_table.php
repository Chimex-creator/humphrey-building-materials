<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PHASE 10 — returns become a reviewable workflow.
     *
     * A customer can now request a return from their own order. That request
     * lands as `pending` until staff approve or decline it. Refund bookkeeping
     * (who refunded, when, with what note) rides along on the same row, so
     * refunds stay attached to the exact goods that came back.
     */
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->string('source', 10)->default('staff');   // customer | staff
            $table->string('status', 20)->default('approved'); // pending | approved | declined

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_note', 500)->nullable();

            $table->index(['status', 'created_at']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['order_id', 'status']);
            $table->dropColumn(['source', 'status', 'reviewed_at', 'refunded_at', 'refund_note']);
        });
    }
};
