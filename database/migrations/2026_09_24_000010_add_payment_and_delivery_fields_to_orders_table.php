<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Pickup or delivery — decides whether a delivery fee applies.
            $table->string('delivery_option', 20)->default('pickup')->after('delivery_address');
            // Preferred date the customer wants the order delivered.
            $table->date('preferred_delivery_date')->nullable()->after('delivery_option');
            // not_applicable (pickup) | pending_confirmation | confirmed
            $table->string('delivery_fee_status', 30)->default('not_applicable')->after('delivery_fee');
            $table->foreignId('confirmed_by')->nullable()->after('delivery_fee_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');

            // How the customer chose to pay: paystack | pay_on_delivery
            $table->string('payment_option', 30)->default('paystack')->after('status');
            // Computed from payments rows: unpaid | pending | paid | failed | refunded
            $table->string('payment_status', 30)->default('unpaid')->after('payment_option');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropIndex(['payment_status']);
            $table->dropColumn([
                'delivery_option',
                'preferred_delivery_date',
                'delivery_fee_status',
                'confirmed_at',
                'payment_option',
                'payment_status',
            ]);
        });
    }
};
