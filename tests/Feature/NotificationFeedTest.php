<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingCompleted;
use App\Notifications\BookingConfirmed;
use App\Notifications\PaymentReceipt;
use App\Services\BookingNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 2: in-app notification feed.
 *
 * Covers storage, the API contract the mobile client already parses
 * ({id,type,title,body,created_at,is_read}), and per-user isolation.
 */
class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    private User $guest;
    private User $other;
    private User $host;
    private Property $property;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $mk = fn (string $name, string $email, string $role) => User::create([
            'name' => $name, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role,
        ]);

        $this->guest = $mk('Guest', 'guest@example.test', 'customer');
        $this->other = $mk('Other', 'other@example.test', 'customer');
        $this->host  = $mk('Host', 'host@example.test', 'owner');

        $this->property = Property::create([
            'host_id' => $this->host->id,
            'name' => 'Sunrise Lodge',
            'city' => 'Nungwi',
            'area' => 'Zanzibar',
            'price_per_night' => 150000,
            'status' => 'active',
        ]);

        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '101',
            'status' => 'available',
            'price' => 150000,
            'total_inventory' => 1,
        ]);
    }

    private function booking(string $status = 'Pending'): Booking
    {
        return Booking::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
            'total_price' => 150000,
            'commission_rate' => 10,
            'platform_fee' => 15000,
            'owner_payout' => 135000,
            'status' => $status,
            'payment_status' => 'pending',
            'booking_code' => 'BKABC12345',
        ]);
    }

    private function authedAsGuest(): static
    {
        $token = $this->guest->createToken('t')->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    // ── storage ───────────────────────────────────────────────────────────────

    public function test_confirming_a_booking_creates_two_notifications(): void
    {
        Notification::fake();
        $booking = $this->booking();

        $svc = app(BookingNotificationService::class);
        $svc->notifyConfirmed($booking);
        $svc->notifyReceipt($booking);

        Notification::assertSentTo($this->guest, BookingConfirmed::class);
        Notification::assertSentTo($this->guest, PaymentReceipt::class);
    }

    public function test_notifications_are_persisted_to_the_database(): void
    {
        $booking = $this->booking();

        // No fake: exercises the real database channel.
        app(BookingNotificationService::class)->notifyConfirmed($booking);

        $this->assertSame(1, $this->guest->notifications()->count());

        $row = $this->guest->notifications()->first();
        $this->assertSame('App\Notifications\BookingConfirmed', $row->type);
        $this->assertNull($row->read_at, 'new notifications must start unread');

        $data = $row->data;  // already cast to array by DatabaseNotification
        $this->assertSame('Booking confirmed', $data['title']);
        // Real property + real code, never a placeholder.
        $this->assertStringContainsString('Sunrise Lodge', $data['body']);
        $this->assertStringContainsString('BKABC12345', $data['body']);
    }

    public function test_cancelled_and_completed_notifications_render(): void
    {
        $booking = $this->booking('Confirmed');
        $svc = app(BookingNotificationService::class);

        $svc->notifyCancelled($booking);
        $svc->notifyCompleted($booking);

        $this->assertSame(2, $this->guest->notifications()->count());
    }

    // ── API contract ──────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    public function test_index_returns_the_contract_the_mobile_app_parses(): void
    {
        app(BookingNotificationService::class)->notifyConfirmed($this->booking());

        $res = $this->authedAsGuest()->getJson('/api/notifications')->assertStatus(200);

        $body = $res->json();
        $this->assertCount(1, $body['notifications']);

        $n = $body['notifications'][0];
        foreach (['id', 'type', 'title', 'body', 'created_at', 'is_read'] as $key) {
            $this->assertArrayHasKey($key, $n, "missing client contract key: {$key}");
        }

        // Stable machine key, not a PHP class name.
        $this->assertSame('booking_confirmed', $n['type']);
        $this->assertFalse($n['is_read']);
        // Legacy alias the mobile client also falls back to.
        $this->assertFalse($n['read']);
        $this->assertSame(1, $body['unread_count']);
    }

    public function test_unread_count_endpoint(): void
    {
        $svc = app(BookingNotificationService::class);
        $booking = $this->booking();
        $svc->notifyConfirmed($booking);
        $svc->notifyReceipt($booking);

        $body = $this->authedAsGuest()
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(200)
            ->json();

        $this->assertSame(2, $body['unread_count']);
    }

    public function test_mark_read_clears_one_and_updates_the_count(): void
    {
        $svc = app(BookingNotificationService::class);
        $booking = $this->booking();
        $svc->notifyConfirmed($booking);
        $svc->notifyReceipt($booking);

        $id = $this->guest->notifications()->first()->id;

        $body = $this->authedAsGuest()
            ->patchJson("/api/notifications/{$id}/read")
            ->assertStatus(200)
            ->json();

        $this->assertTrue($body['notification']['is_read']);
        $this->assertSame(1, $body['unread_count']);
        $this->assertNotNull($this->guest->notifications()->first()->read_at);
    }

    public function test_mark_read_is_idempotent(): void
    {
        app(BookingNotificationService::class)->notifyConfirmed($this->booking());
        $id = $this->guest->notifications()->first()->id;

        $this->authedAsGuest()->patchJson("/api/notifications/{$id}/read")->assertStatus(200);
        $this->authedAsGuest()->patchJson("/api/notifications/{$id}/read")->assertStatus(200);

        $this->assertSame(0, $this->guest->unreadNotifications()->count());
    }

    public function test_mark_all_read(): void
    {
        $svc = app(BookingNotificationService::class);
        $booking = $this->booking();
        $svc->notifyConfirmed($booking);
        $svc->notifyReceipt($booking);

        $body = $this->authedAsGuest()
            ->postJson('/api/notifications/read-all')
            ->assertStatus(200)
            ->json();

        $this->assertSame(2, $body['updated']);
        $this->assertSame(0, $body['unread_count']);
        $this->assertSame(0, $this->guest->unreadNotifications()->count());
    }

    public function test_unread_filter(): void
    {
        $svc = app(BookingNotificationService::class);
        $booking = $this->booking();
        $svc->notifyConfirmed($booking);
        $svc->notifyReceipt($booking);

        $this->authedAsGuest()->patchJson(
            '/api/notifications/' . $this->guest->notifications()->first()->id . '/read'
        )->assertStatus(200);

        $body = $this->authedAsGuest()
            ->getJson('/api/notifications?unread=1')
            ->assertStatus(200)
            ->json();

        $this->assertCount(1, $body['notifications']);
    }

    // ── isolation ─────────────────────────────────────────────────────────────

    public function test_a_user_cannot_read_another_users_notification(): void
    {
        app(BookingNotificationService::class)->notifyConfirmed($this->booking());
        $foreignId = $this->guest->notifications()->first()->id;

        $token = $this->other->createToken('t')->plainTextToken;

        // 404, not 403: ids must not be probeable.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson("/api/notifications/{$foreignId}/read")
            ->assertStatus(404);

        $this->assertNull($this->guest->notifications()->first()->read_at, 'victim notification was marked read');
    }

    public function test_inbox_never_leaks_another_users_notifications(): void
    {
        app(BookingNotificationService::class)->notifyConfirmed($this->booking());

        $token = $this->other->createToken('t')->plainTextToken;
        $body = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/notifications')
            ->assertStatus(200)
            ->json();

        $this->assertCount(0, $body['notifications']);
        $this->assertSame(0, $body['unread_count']);
    }

    public function test_mark_all_read_only_touches_the_caller(): void
    {
        app(BookingNotificationService::class)->notifyConfirmed($this->booking());

        $token = $this->other->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/notifications/read-all')
            ->assertStatus(200);

        $this->assertSame(1, $this->guest->unreadNotifications()->count());
    }

    public function test_unknown_id_returns_404(): void
    {
        $this->authedAsGuest()
            ->patchJson('/api/notifications/does-not-exist/read')
            ->assertStatus(404);
    }
}
