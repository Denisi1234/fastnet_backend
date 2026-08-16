<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // High-frequency search & filtering composite indexes
            if (!Schema::hasIndex('properties', ['city', 'status'])) {
                $table->index(['city', 'status']);
            }
            if (!Schema::hasIndex('properties', ['price_per_night'])) {
                $table->index('price_per_night');
            }
        });

        Schema::table('rooms', function (Blueprint $table) {
            // High-frequency capacity & availability lookup indexes
            if (!Schema::hasIndex('rooms', ['property_id', 'capacity', 'status'])) {
                $table->index(['property_id', 'capacity', 'status']);
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Fast overlap date range and status lookups
            if (!Schema::hasIndex('bookings', ['room_id', 'check_in', 'check_out', 'status'])) {
                $table->index(['room_id', 'check_in', 'check_out', 'status']);
            }
            if (!Schema::hasIndex('bookings', ['guest_id', 'created_at'])) {
                $table->index(['guest_id', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['city', 'status']);
            $table->dropIndex(['price_per_night']);
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex(['property_id', 'capacity', 'status']);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'check_in', 'check_out', 'status']);
            $table->dropIndex(['guest_id', 'created_at']);
        });
    }
};
