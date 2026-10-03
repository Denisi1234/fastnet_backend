<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes for the passwordless "sign in with a code" flow.
 *
 * Deliberately a separate table from `password_reset_tokens` rather than a
 * `purpose` column on it:
 *  - `password_reset_tokens` has `email` as its PRIMARY KEY, so it holds exactly
 *    one row per address. Reusing it would make requesting a sign-in code
 *    silently destroy a password reset the user had already requested.
 *  - The two flows have different rules (a sign-in code must never be able to
 *    reset a password, and vice versa), so they must never share a row.
 *
 * The code is stored as a SHA-256 hash, never in plaintext, so a database leak
 * or a stray backup cannot be replayed as a live sign-in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('login_otp_codes')) {
            return;
        }

        Schema::create('login_otp_codes', function (Blueprint $table) {
            $table->id();

            // Null while the contact has no account yet: the user row is only
            // created once the code proves control of the contact.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Normalised email address or E.164 phone number, as entered.
            $table->string('contact', 255);

            // 'email' or 'sms' — which channel the code was actually delivered on.
            $table->string('channel', 16)->default('email');

            // SHA-256 of the 6-digit code, hex encoded (64 chars).
            $table->string('token_hash', 64);

            // Guards against brute-forcing a 6-digit code: 1,000,000 guesses at
            // 5 attempts per code is not worth attacking.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['contact', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otp_codes');
    }
};