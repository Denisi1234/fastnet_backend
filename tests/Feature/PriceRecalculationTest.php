<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Property;
use App\Models\Room;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PriceRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private User $guest;
    private Property $property;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::create([
            'name' => 'Lodge Host',
            'email' => 'host_recalc@example.com',
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        $this->guest = User::create([
            'name' => 'Guest User',
            'email' => 'guest_recalc@example.com',
            'password' => bcrypt('password'),
            'role' => 'customer',
        ]);

        $this->property = Property::create([
            'name' => 'Serengeti Luxury Safari Lodge',
            'city' => 'Arusha',
            'area' => 'National Park',
            'price_per_night' => 100000.00,
            'host_id' => $this->host->id,
            'status' => 'Active',
        ]);

        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '201',
            'status' => 'available',
            'price' => 100000.00,
            'capacity' => 2,
            'total_inventory' => 2,
        ]);
    }

    public function test_checkout_ignores_frontend_tampered_price()
    {
        // Frontend attempts to submit price = 1 TZS (tampered price attack).
        // Dates are relative to today — hardcoded dates rotted into the past
        // and tripped the "check_in must be after or equal to today" rule.
        $tamperedPayload = [
            'room_id' => $this->room->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(8)->toDateString(), // 3 nights
            'quantity' => 1,
            'guests' => 2,
            'price' => 1.00,
            'total_price' => 1.00,
            'subtotal' => 1.00,
        ];

        $response = $this->actingAs($this->guest)
            ->postJson('/api/bookings', $tamperedPayload);

        $response->assertStatus(201);

        // Expected real pricing calculated authoritatively with AzamPay 1% fee:
        // Owner base: 100,000 * 3 nights = 300,000 TZS
        // AzamPay 1% processing fee = 3,000 TZS
        // Grand Total = 303,000 TZS
        $data = $response->json();

        $this->assertEquals('TZS', $data['currency']);
        $this->assertEquals(3, $data['nights']);
        $this->assertEquals(101000.00, $data['nightly_rate']);
        $this->assertEquals(303000.00, $data['subtotal']);
        $this->assertEquals(303000.00, $data['grand_total']);
        $this->assertEquals(303000.00, $data['total_price']);

        // Assert database persisted total_price matches authoritative customer total (303,000), NOT 1.00
        $booking = Booking::where('booking_code', $data['booking_code'])->first();
        $this->assertNotNull($booking);
        $this->assertEquals(303000.00, (float) $booking->total_price);
    }
}
