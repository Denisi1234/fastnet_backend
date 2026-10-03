<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One live hold per guest per room per stay.
 *
 * lockRoom() relies on updateOrCreate(['room_id','guest_id','check_in',
 * 'check_out']), but room_locks had no unique index on those columns. Two
 * concurrent requests could both miss the existing row and insert, leaving
 * duplicate holds - and duplicate holds defeat the point of the lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_locks', function (Blueprint $table) {
            if (! Schema::hasIndex('room_locks', ['room_id', 'guest_id', 'check_in', 'check_out'])) {
                $table->unique(
                    ['room_id', 'guest_id', 'check_in', 'check_out'],
                    'room_locks_guest_stay_unique'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_locks', function (Blueprint $table) {
            if (Schema::hasIndex('room_locks', ['room_id', 'guest_id', 'check_in', 'check_out'])) {
                $table->dropUnique('room_locks_guest_stay_unique');
            }
        });
    }
};
