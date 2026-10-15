<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest and host are separate people: the same email address may own one
 * guest account AND one host account (separate rows, separate passwords,
 * separate sign-ins). Replace the global unique on users.email with a
 * composite unique on (email, role).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropUnique(['email']);
            } catch (\Throwable $e) {
                // Index may already be gone on fresh installs.
            }
            $table->unique(['email', 'role'], 'users_email_role_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropUnique('users_email_role_unique');
            } catch (\Throwable $e) {
            }
            $table->unique('email');
        });
    }
};
