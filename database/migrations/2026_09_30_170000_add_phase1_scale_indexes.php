<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 scale-up: geo + role + owner-scope indexes.
 * - properties(latitude, longitude): viewport bounds + radius filters
 * - users(role): admin users?role=owner lookups
 * - properties(host_id, status): owner mine-scope + verification queues
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasIndex('properties', ['latitude', 'longitude'])) {
                $table->index(['latitude', 'longitude'], 'properties_lat_lng_idx');
            }
            if (!Schema::hasIndex('properties', ['host_id', 'status'])) {
                $table->index(['host_id', 'status'], 'properties_host_status_idx');
            }
        });
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasIndex('users', ['role'])) {
                $table->index(['role'], 'users_role_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_lat_lng_idx');
            $table->dropIndex('properties_host_status_idx');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_idx');
        });
    }
};
