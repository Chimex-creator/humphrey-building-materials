<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phases 21–24 — delivery workflow records.
 *
 *  - failure_reason / failure_note : why an attempt did not complete
 *  - rescheduled_date              : the date agreed when it failed
 *  - delivered_at / received_by / confirmation_note : proof of delivery
 *
 * The statuses themselves do not change — these fields make each
 * transition explain itself instead of being a bare button press.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('failure_reason', 40)->nullable()->after('notes');
            $table->string('failure_note', 500)->nullable()->after('failure_reason');
            $table->date('rescheduled_date')->nullable()->after('failure_note');
            $table->timestamp('delivered_at')->nullable()->after('rescheduled_date');
            $table->string('received_by', 100)->nullable()->after('delivered_at');
            $table->string('confirmation_note', 500)->nullable()->after('received_by');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn([
                'failure_reason',
                'failure_note',
                'rescheduled_date',
                'delivered_at',
                'received_by',
                'confirmation_note',
            ]);
        });
    }
};
