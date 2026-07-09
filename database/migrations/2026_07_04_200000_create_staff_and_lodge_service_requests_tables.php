<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('role');
            $table->string('phone');
            $table->string('status')->default('Off-Duty'); // On-Duty, Off-Duty
            $table->string('room')->default('None'); // None, Front Desk, or Room XXX
            $table->foreignId('host_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('lodge_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained('users')->onDelete('cascade');
            $table->string('room_number');
            $table->string('type'); // food_order, laundry, cleaning, amenities, maintenance, spa, taxi
            $table->json('details')->nullable(); // holds details like food items/notes/preferredTime
            $table->decimal('price', 12, 2)->default(0.00);
            $table->string('status'); // e.g. Received/Preparing/Ready/On the Way/Delivered or Pending/In Progress/Completed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lodge_service_requests');
        Schema::dropIfExists('staff');
    }
};
