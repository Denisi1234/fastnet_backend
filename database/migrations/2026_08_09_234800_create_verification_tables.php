<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Owner verification documents & profile extension
        Schema::create('owner_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('full_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('id_number')->nullable(); // National ID or passport
            $table->string('id_document_url')->nullable(); // ID/passport copy
            $table->string('business_registration_number')->nullable();
            $table->string('business_document_url')->nullable(); // License or certificate copy
            $table->string('payout_bank_name')->nullable();
            $table->string('payout_account_number')->nullable();
            $table->string('payout_account_name')->nullable();
            $table->string('status')->default('submitted'); // draft, submitted, under_review, changes_requested, approved, rejected, suspended
            $table->text('admin_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        // 2. Lodge documents table
        Schema::create('lodge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->onDelete('cascade');
            $table->string('document_type'); // business_license, tourism_license, registration_certificate, tax_id, safety_certificate
            $table->string('document_number')->nullable();
            $table->string('file_url');
            $table->string('status')->default('pending'); // pending, verified, rejected
            $table->timestamps();
        });

        // 3. Complete Verification Audit Trail (requests & reviews)
        Schema::create('verification_requests', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type'); // owner, lodge
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('submitted_by')->constrained('users')->onDelete('cascade');
            $table->string('status')->default('submitted'); // submitted, under_review, changes_requested, approved, rejected, suspended
            $table->text('reason')->nullable(); // Feedback for rejection or changes requested
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_requests');
        Schema::dropIfExists('lodge_documents');
        Schema::dropIfExists('owner_verifications');
    }
};
