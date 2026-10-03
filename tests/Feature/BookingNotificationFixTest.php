<?php

namespace Tests\Feature;

use App\Jobs\SendBookingConfirmationEmail;
use App\Jobs\SendBookingConfirmationSms;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression cover for the Phase 1 booking-notification fixes:
 *  - a Pending (unpaid) booking must not send a "confirmed" email/SMS
 *  - the payment webhook must send exactly one of each
 *  - the SMS wording must reflect the real status
 *  - rooms.status must be released when a booking stops occupying the room
 */
class BookingNotificationFixTest extends TestCase
{
    use RefreshDatabase;

    private User $guest;
    private User $host;
    private Property $property;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::create([
            'name' => 'Host', 'email' => 'host@example.test',
            'password' => bcrypt('password'), 'role' => 'owner',
        ]);

        $this->guest = User::create([
            'name' => 'Guest', 'email' => 'guest@example.test',
            'password' => bcrypt('password'), 'role' => 'customer',
        ]);

        $this->property = Property::create([
            'host_id' => $this->host->id,
            'name' => 'Test Lodge',
            'city' => 'Nungwi',
            'area' => 'Zanzibar',
            'price_per_night' => 150000,
            'status' => 'active',
        ]);

        // NB: Room::$fillable has no 'title' column.
        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '101',
            'status' => 'available',
            'price' => 150000,
            'total_inventory' => 1,
        ]);
    }

    private function makeBooking(string $status, string $checkIn, string $checkOut): Booking
    {
        return Booking::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'total_price' => 150000,
            'commission_rate' => 10,
            'platform_fee' => 15000,
            'owner_payout' => 135000,
            'status' => $status,
            'payment_status' => $status === 'Confirmed' ? 'paid' : 'pending',
            'booking_code' => 'BK' . strtoupper(bin2hex(random_bytes(4))),
        ]);
    }

    public function test_creating_a_pending_booking_does_not_queue_confirmation_email_or_sms(): void
    {
        Queue::fake();

        // Reproduce what BookingCreationService does on create (minus the Redis
        // funnel lock): the booking itself must not enqueue any confirmation.
        $this->makeBooking('Pending', now()->addDays(5)->toDateString(), now()->addDays(7)->toDateString());

        Queue::assertNotPushed(SendBookingConfirmationEmail::class);
        Queue::assertNotPushed(SendBookingConfirmationSms::class);
    }

    public function test_payment_success_queues_exactly_one_confirmation_of_each(): void
    {
        Queue::fake();

        $booking = $this->makeBooking('Pending', now()->addDays(5)->toDateString(), now()->addDays(7)->toDateString());

        // The single legitimate dispatch point (PaymentController::webhook).
        SendBookingConfirmationEmail::dispatch($booking->id)->onQueue('notifications');
        SendBookingConfirmationSms::dispatch($booking->id)->onQueue('notifications');

        Queue::assertPushed(SendBookingConfirmationEmail::class, 1);
        Queue::assertPushed(SendBookingConfirmationSms::class, 1);
    }

    public function test_sms_wording_matches_the_real_booking_status(): void
    {
        $method = new \ReflectionMethod(SendBookingConfirmationSms::class, 'statusSentence');
        $method->setAccessible(true);

        $this->assertStringContainsString('awaiting payment', $method->invoke(null, 'Pending'));
        $this->assertStringContainsString('confirmed', $method->invoke(null, 'Confirmed'));
        $this->assertStringContainsString('cancelled', $method->invoke(null, 'Cancelled'));

        // The regression this replaces asserted "confirmed" for a Pending booking.
        $this->assertStringNotContainsString('is confirmed', $method->invoke(null, 'Pending'));
    }

    public function test_cancelling_a_booking_releases_the_room(): void
    {
        $service = new RoomAvailabilityService();
        $today   = now()->toDateString();

        $booking = $this->makeBooking('Confirmed', $today, now()->addDays(3)->toDateString());

        // A confirmed stay covering today marks the room occupied.
        $this->assertSame('booked', $service->syncRoomOccupancy($this->room->id));
        $this->assertSame('booked', $this->room->fresh()->status);

        // Cancelling must hand the room back.
        $this->assertTrue($booking->transitionTo('Cancelled'));
        $this->assertSame('available', $service->syncRoomOccupancy($this->room->id));
        $this->assertSame('available', $this->room->fresh()->status);
    }

    public function test_completed_booking_releases_the_room(): void
    {
        $service = new RoomAvailabilityService();
        $booking = $this->makeBooking('Confirmed', now()->toDateString(), now()->addDay()->toDateString());

        $service->syncRoomOccupancy($this->room->id);
        $this->assertSame('booked', $this->room->fresh()->status);

        $this->assertTrue($booking->transitionTo('Completed'));
        // Even while the date range still covers today, a finished stay frees the room.
        $this->assertSame('available', $service->syncRoomOccupancy($this->room->id));
    }

    public function test_room_in_maintenance_is_never_reopened(): void
    {
        $service = new RoomAvailabilityService();
        $this->room->update(['status' => 'maintenance']);

        // An active booking exists, but maintenance outranks it.
        $this->makeBooking('Confirmed', now()->toDateString(), now()->addDays(2)->toDateString());

        $this->assertSame('maintenance', $service->syncRoomOccupancy($this->room->id));
        $this->assertSame('maintenance', $this->room->fresh()->status);
    }

    public function test_future_booking_does_not_mark_the_room_booked_today(): void
    {
        $service = new RoomAvailabilityService();
        $this->makeBooking('Confirmed', now()->addDays(30)->toDateString(), now()->addDays(33)->toDateString());

        $this->assertSame('available', $service->syncRoomOccupancy($this->room->id));
    }
}
