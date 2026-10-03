<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets can legitimately belong to a guest with no account.
 *
 * tickets.user_id was NOT NULL, and TicketController worked around that by
 * writing every guest's ticket to user id 1 - so unrelated people's tickets
 * appeared in one real account's support history, and cascading deletes on
 * that account destroyed them.
 *
 * The submitter's own details already live in the ticket body/description, so
 * making the owner nullable loses no information.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Rows with no owner cannot satisfy the original constraint.
            DB::table('tickets')->whereNull('user_id')->delete();

            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};