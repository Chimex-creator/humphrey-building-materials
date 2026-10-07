<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL SPEC §23 / §30 — two small schema changes:
 *
 *  - users.avatar_path : optional profile picture (§30)
 *  - support_tickets   : any legacy "closed" row becomes "resolved" because
 *                        the customer-care workflow is exactly
 *                        Open → In Progress → Resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('address');
        });

        DB::table('support_tickets')->where('status', 'closed')->update(['status' => 'resolved']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
