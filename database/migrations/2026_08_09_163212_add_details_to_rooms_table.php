<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('room_type_id')->nullable();
            $table->decimal('price', 12, 2)->default(0.00);
            $table->integer('capacity')->default(1);
            $table->text('amenities')->nullable();
            $table->text('photos')->nullable();
            $table->text('description')->nullable();
            $table->string('floor')->nullable();
            $table->integer('max_adults')->default(1);
            $table->integer('max_children')->default(0);
            $table->string('bed_configuration')->nullable();
            $table->integer('number_of_beds')->default(1);
            $table->string('room_size')->nullable();
            $table->integer('total_inventory')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'room_type_id', 'price', 'capacity', 'amenities', 'photos',
                'description', 'floor', 'max_adults', 'max_children',
                'bed_configuration', 'number_of_beds', 'room_size'
            ]);
        });
    }
};
