<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extra product information required by §12 / §15 / §17.
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand', 100)->nullable()->after('unit');
            $table->text('specifications')->nullable()->after('description');
            // Minimum order quantity and quantity step (e.g. cement sold in 50-bag lots).
            $table->unsignedInteger('min_order_quantity')->default(1)->after('stock_quantity');
            $table->unsignedInteger('quantity_step')->default(1)->after('min_order_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'brand',
                'specifications',
                'min_order_quantity',
                'quantity_step',
            ]);
        });
    }
};
