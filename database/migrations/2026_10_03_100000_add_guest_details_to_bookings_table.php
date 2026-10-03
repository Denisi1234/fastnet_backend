<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep what the guest actually told us.
 *
 * The checkout form collects a payment method + phone number, room/bed
 * preferences and free-text special requests, but none of those had a column —
 * so every booking silently dropped the guest's mobile-money number (needed
 * for reconciliation) and their notes to the host. These columns close that
 * gap; BookingCreationService stores them at create time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'payment_method')) {
                $table->string('payment_method', 32)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'payment_phone')) {
                $table->string('payment_phone', 32)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'special_requests')) {
                $table->text('special_requests')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['payment_method', 'payment_phone', 'special_requests'] as $col) {
                if (Schema::hasColumn('bookings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};