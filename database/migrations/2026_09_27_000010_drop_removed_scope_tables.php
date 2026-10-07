<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Scope Update §5 — permanently removed features.
 *
 * 1. Partial payment / customer credit  → users.allow_partial_payment
 * 2. Saved / favourite products        → saved_products
 * 3. Recently viewed products          → recently_viewed_products
 *
 * None of these tables hold historical business data (orders, stock and
 * payments are untouched), so dropping them cannot destroy anything the
 * retained features depend on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('allow_partial_payment');
        });

        Schema::dropIfExists('saved_products');
        Schema::dropIfExists('recently_viewed_products');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('allow_partial_payment')->default(false);
        });

        if (! Schema::hasTable('saved_products')) {
            Schema::create('saved_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('recently_viewed_products')) {
            Schema::create('recently_viewed_products', function (Blueprint $table) {
                $table->id();
                $table->string('session_key', 64);
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->timestamp('viewed_at')->nullable();
                $table->timestamps();
                $table->index(['session_key', 'viewed_at']);
            });
        }
    }
};
