<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasIndex('bookings', ['payment_status', 'status'])) {
                $table->index(['payment_status', 'status']);
            }
            if (!Schema::hasIndex('bookings', ['total_price'])) {
                $table->index('total_price');
            }
            if (!Schema::hasIndex('bookings', ['created_at'])) {
                $table->index('created_at');
            }
            if (!Schema::hasIndex('bookings', ['booking_code'])) {
                $table->index('booking_code');
            }
        });

        Schema::table('payouts', function (Blueprint $table) {
            if (!Schema::hasIndex('payouts', ['owner_id', 'status'])) {
                $table->index(['owner_id', 'status']);
            }
            if (!Schema::hasIndex('payouts', ['property_id'])) {
                $table->index('property_id');
            }
            if (!Schema::hasIndex('payouts', ['amount'])) {
                $table->index('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['payment_status', 'status']);
            $table->dropIndex(['total_price']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['booking_code']);
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'status']);
            $table->dropIndex(['property_id']);
            $table->dropIndex(['amount']);
        });
    }
};
