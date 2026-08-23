<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('payout_reference')->unique();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('property_id')->nullable()->constrained('properties')->onDelete('set null');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method')->default('Mobile Money'); // Mobile Money, Bank Transfer, Manual
            $table->string('account_details')->nullable();
            $table->string('status')->default('REQUESTED'); // AVAILABLE, REQUESTED, PROCESSING, PAID, FAILED
            $table->text('notes')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
