<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->foreignId('guest_id')->constrained('users')->onDelete('cascade');
            $table->date('check_in');
            $table->date('check_out');
            $table->timestamp('expires_at');
            $table->timestamps();

            // Index to search faster
            $table->index(['room_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_locks');
    }
};
