<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * True pending registrations (Phase 2 of the final master prompt).
 *
 * A guest who registers is NOT turned into a User row straight away —
 * their details (with the password already hashed by the model cast)
 * wait here until the emailed signed link is clicked. Only then is the
 * permanent customer account created, and this row is deleted.
 *
 * While a registration is pending there is no User row at all, so the
 * `auth` middleware keeps the person out of every customer-only screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');                       // hashed — never plaintext
            $table->timestamp('expires_at');                  // verification link lifetime
            $table->string('ip_address', 45)->nullable();     // who registered
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
