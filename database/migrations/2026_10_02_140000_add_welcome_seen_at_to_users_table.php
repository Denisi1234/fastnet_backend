<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time first-signin stamp.
 *
 * The in-app welcome message must appear on a user's FIRST sign-in and never
 * again. Without a durable stamp that check would have to be inferred from the
 * notifications table, which the user can delete rows from.
 *
 * Deliberately NOT added to User::$fillable: it is server-owned state and must
 * never arrive from a request body.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'welcome_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // No ->after(): users has no last_login_at column to anchor to.
            $table->timestamp('welcome_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'welcome_seen_at')) {
                $table->dropColumn('welcome_seen_at');
            }
        });
    }
};
