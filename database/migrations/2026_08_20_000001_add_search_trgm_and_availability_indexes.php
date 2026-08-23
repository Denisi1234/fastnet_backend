<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add GIN trigram indexes for fast case-insensitive ILIKE searches on
 * city, area, name, address — enabling index usage even with leading wildcards.
 *
 * Also adds a composite index for the availability date-range queries on bookings
 * and a status-based index for property hot-path filtering.
 *
 * Requires pg_trgm extension (enabled by default on Supabase).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Enable pg_trgm extension if not already active (safe to run multiple times)
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');

            // ── Properties: GIN trigram indexes for ILIKE '%...%' searches ─────────────
            // These allow Postgres to use an index even with leading wildcards like ILIKE '%arusha%'
            DB::statement('CREATE INDEX IF NOT EXISTS properties_city_trgm_idx
                ON properties USING gin (city gin_trgm_ops);');

            DB::statement('CREATE INDEX IF NOT EXISTS properties_area_trgm_idx
                ON properties USING gin (area gin_trgm_ops);');

            DB::statement('CREATE INDEX IF NOT EXISTS properties_name_trgm_idx
                ON properties USING gin (name gin_trgm_ops);');

            DB::statement('CREATE INDEX IF NOT EXISTS properties_address_trgm_idx
                ON properties USING gin (address gin_trgm_ops);');
        }


        // ── Properties: status + created_at for sorted active-lodge listing ──────────
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasIndex('properties', ['status', 'created_at'])) {
                $table->index(['status', 'created_at'], 'properties_status_created_at_idx');
            }
        });

        // ── Bookings: composite index for fast date-range overlap queries ─────────────
        // Used by RoomAvailabilityService bulk query:
        //   WHERE room_id = ? AND check_in < ? AND check_out > ? AND status NOT IN (...)
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasIndex('bookings', ['room_id', 'check_in', 'check_out'])) {
                $table->index(['room_id', 'check_in', 'check_out'], 'bookings_room_dates_idx');
            }
        });

        // ── RoomLocks: index for active-lock overlap queries ──────────────────────────
        // Used by RoomAvailabilityService bulk lock query:
        //   WHERE room_id = ? AND expires_at > now() AND check_in < ? AND check_out > ?
        Schema::table('room_locks', function (Blueprint $table) {
            if (!Schema::hasIndex('room_locks', ['room_id', 'expires_at'])) {
                $table->index(['room_id', 'expires_at'], 'room_locks_room_expires_idx');
            }
        });
    }


    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS properties_city_trgm_idx;');
        DB::statement('DROP INDEX IF EXISTS properties_area_trgm_idx;');
        DB::statement('DROP INDEX IF EXISTS properties_name_trgm_idx;');
        DB::statement('DROP INDEX IF EXISTS properties_address_trgm_idx;');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_status_created_at_idx');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_room_dates_idx');
        });
        Schema::table('room_locks', function (Blueprint $table) {
            $table->dropIndex('room_locks_room_expires_idx');
        });
    }
};
