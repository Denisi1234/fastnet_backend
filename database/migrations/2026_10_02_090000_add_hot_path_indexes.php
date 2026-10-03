<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hot-path indexes identified while profiling the API.
 *
 * Every index here backs a filter that was running as a sequential scan:
 *  - users(phone_number)            login + easy-auth phone lookup
 *  - users(role, status)            owner/admin verification queues
 *  - payments(booking_id, status)   payment status polling
 *  - messages(sender_id, recipient_id, created_at)  thread + history reads
 *  - owner_verifications(user_id)   owner verification lookups
 *  - reviews(property_id, created_at)  review lists ordered newest-first
 *  - rooms(property_id, status, created_at)  room lists ordered newest-first
 *  - lodge_service_requests / staff / wishlist_lists owner scoping
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: array<int, string>, 2: string}> */
    private array $indexes = [
        ['users',            ['phone_number'],                              'users_phone_number_idx'],
        ['users',            ['role', 'status'],                            'users_role_status_idx'],
        ['payments',         ['booking_id', 'status'],                      'payments_booking_status_idx'],
        ['messages',         ['sender_id', 'recipient_id', 'created_at'],   'messages_thread_idx'],
        ['messages',         ['recipient_id', 'unread'],                    'messages_unread_idx'],
        ['owner_verifications', ['user_id'],                                'owner_verifications_user_idx'],
        ['verification_requests', ['entity_type', 'entity_id', 'created_at'], 'verification_requests_entity_idx'],
        ['lodge_documents',  ['property_id'],                               'lodge_documents_property_idx'],
        ['reviews',          ['property_id', 'created_at'],                 'reviews_property_created_idx'],
        ['rooms',            ['property_id', 'status', 'created_at'],      'rooms_property_status_created_idx'],
        ['lodge_service_requests', ['guest_id', 'created_at'],              'lodge_service_requests_guest_idx'],
        ['staff',            ['host_id', 'created_at'],                     'staff_host_created_idx'],
        ['wishlist_lists',   ['user_id'],                                   'wishlist_lists_user_idx'],
        ['payouts',          ['status', 'created_at'],                      'payouts_status_created_idx'],
        ['tickets',          ['status', 'updated_at'],                      'tickets_status_updated_idx'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (Schema::hasIndex($table, $columns)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (! Schema::hasIndex($table, $columns)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }
};
