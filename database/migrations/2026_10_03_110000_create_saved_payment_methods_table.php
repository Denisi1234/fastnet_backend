<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved mobile-money numbers per user.
 *
 * The payment-detail page already lists, adds and deletes these; without the
 * table every call failed and the section always read empty. Deliberately
 * MNO-only (provider + phone + label): there is no card gateway, and storing
 * card PANs without one would be a PCI liability, not a feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_payment_methods')) {
            return;
        }

        Schema::create('saved_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('phone_number', 32);
            $table->string('label', 64)->nullable();
            $table->string('name', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'phone_number']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_payment_methods');
    }
};