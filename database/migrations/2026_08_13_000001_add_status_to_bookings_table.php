<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Booking lifecycle: Pending → Confirmed → Completed | Cancelled
            $table->string('status')->default('Pending')->after('payment_reference');
            // booking_code for receipt generation and guest reference
            $table->string('booking_code')->nullable()->unique()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['status', 'booking_code']);
        });
    }
};
