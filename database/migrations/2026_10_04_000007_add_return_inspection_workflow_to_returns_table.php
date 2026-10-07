<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL SPEC §27 / §28 — returns get a physical inspection workflow and the
 * website refund system is removed from the schema.
 *
 * Returns flow: Requested → In Review → Approved/Rejected → Return Received
 *               → Inspection → Return Completed.
 *
 * Inspection records how many units are resellable (they go back to sellable
 * stock on completion) and how many are damaged (they never do). No money
 * columns remain: refunds are handled outside this website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('reviewed_at');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();

            $table->timestamp('inspected_at')->nullable()->after('received_at');
            $table->foreignId('inspected_by')->nullable()->after('inspected_at')->constrained('users')->nullOnDelete();

            $table->unsignedInteger('resellable_quantity')->nullable()->after('inspected_at');
            $table->unsignedInteger('damaged_quantity')->default(0)->after('resellable_quantity');
            $table->string('inspection_note', 500)->nullable()->after('damaged_quantity');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn([
                'refund_amount',
                'refund_status',
                'refund_note',
                'refunded_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->decimal('refund_amount', 14, 2)->default(0);
            $table->string('refund_status', 20)->default('none');
            $table->string('refund_note', 500)->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();

            $table->dropColumn([
                'received_at',
                'received_by',
                'inspected_at',
                'inspected_by',
                'resellable_quantity',
                'damaged_quantity',
                'inspection_note',
            ]);
        });
    }
};
