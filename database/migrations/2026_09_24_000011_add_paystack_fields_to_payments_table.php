<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Paystack's own transaction id (returned by the verify API).
            $table->unsignedBigInteger('transaction_id')->nullable()->after('reference');
            // How the money arrived: card, bank_transfer, ussd, pay_button...
            $table->string('channel', 40)->nullable()->after('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['transaction_id', 'channel']);
        });
    }
};
