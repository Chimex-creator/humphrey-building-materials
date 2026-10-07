<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL SPEC §24 / §26 — customer-care contacts become database-driven.
 *
 * Admin adds/edits/deactivates contacts with a label (Customer Care, Sales
 * Support, Delivery Support...). Homepage and customer-care sections read
 * only the active rows instead of hard-coding a phone number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('label', 80);
            $table->string('phone', 40);
            $table->string('status', 20)->default('active'); // active | inactive
            $table->unsignedSmallInteger('contact_order')->default(0);
            $table->timestamps();
        });

        // Starter contact from the business information (§1).
        DB::table('business_contacts')->insert([
            [
                'label' => 'Customer Care',
                'phone' => '+2348153667923',
                'status' => 'active',
                'contact_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_contacts');
    }
};
