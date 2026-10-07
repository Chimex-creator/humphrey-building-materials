<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inventory-related returns (§40 / Phase 9). The refund/credit columns
        // are created now so Phase 10 (Returns and Refunds) needs no schema
        // change — it only starts filling them in.
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason', 500);
            $table->timestamp('restocked_at')->nullable();          // null = stock not yet added back
            $table->decimal('refund_amount', 14, 2)->default(0);    // Phase 10
            $table->string('refund_status', 20)->default('none');   // none | pending | refunded (Phase 10)
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
