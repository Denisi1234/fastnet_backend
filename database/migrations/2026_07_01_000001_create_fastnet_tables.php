<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modify default users table (we can do it via a separate migration or direct schema)
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'role')) {
                    $table->string('role')->default('customer'); // customer, owner, admin
                }
                if (!Schema::hasColumn('users', 'phone_number')) {
                    $table->string('phone_number')->nullable();
                }
            });
        }

        // Properties table
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('city');
            $table->string('area');
            $table->decimal('price_per_night', 12, 2);
            $table->foreignId('host_id')->constrained('users')->onDelete('cascade');
            $table->string('image_url')->nullable();
            $table->timestamps();
        });

        // Rooms table
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->onDelete('cascade');
            $table->string('room_number');
            $table->string('status')->default('available'); // available, booked
            $table->timestamps();
        });

        // Bookings table
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->foreignId('guest_id')->constrained('users')->onDelete('cascade');
            $table->date('check_in');
            $table->date('check_out');
            $table->decimal('total_price', 12, 2);
            $table->string('payment_status')->default('pending'); // pending, paid, refunded
            $table->string('payment_reference')->nullable();
            $table->timestamps();
        });

        // Payments table
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->string('gateway'); // stripe, flutterwave, mpesa
            $table->string('transaction_id')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending'); // pending, successful, failed, refunded
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('properties');
        
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['role', 'phone_number']);
            });
        }
    }
};
