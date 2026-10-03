<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications.
 *
 * The stock Laravel notifications table was stripped from the base users
 * migration, so there was nowhere for a notification to be stored even though
 * User already mixes in the Notifiable trait. The mobile client has been
 * calling GET /notifications and PATCH /notifications/{id}/read against this
 * and receiving 404.
 *
 * Schema follows Laravel's standard: uuid primary key, morphs to the
 * notifiable, a JSON data payload and read_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Drives the inbox: newest-first for one recipient, plus the unread
        // badge count which filters on read_at IS NULL for that recipient.
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'notifications_inbox_idx'
            );
            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'notifications_unread_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
