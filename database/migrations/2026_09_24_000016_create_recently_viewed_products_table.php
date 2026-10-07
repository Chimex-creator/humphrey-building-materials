<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recently viewed products (§20) — deliberately simple:
        // keyed by the visitor's session so it works for guests AND customers
        // without forcing anyone to log in just to see their history.
        Schema::create('recently_viewed_products', function (Blueprint $table) {
            $table->id();
            $table->string('session_key', 64);
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->timestamps();

            // One row per product per visitor; re-viewing just updates the time.
            $table->unique(['session_key', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recently_viewed_products');
    }
};
