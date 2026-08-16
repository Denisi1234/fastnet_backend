<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\RoomAvailabilityService;
use App\Models\Room;
use App\Models\Booking;
use App\Models\RoomLock;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RoomAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoomAvailabilityService $service;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomAvailabilityService();

        // Create host user first for foreign key integrity
        $host = \App\Models\User::create([
            'name' => 'Host User',
            'email' => 'host@example.com',
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        // Create test property & room with total_inventory = 2
        $property = \App\Models\Property::create([
            'name' => 'Test Hotel',
            'city' => 'Dar es Salaam',
            'area' => 'Kinondoni',
            'price_per_night' => 100000.00,
            'host_id' => $host->id,
            'status' => 'Active'
        ]);

        $this->room = Room::create([
            'property_id' => $property->id,
            'room_number' => '101',
            'status' => 'available',
            'capacity' => 2,
            'price' => 100000.00,
            'total_inventory' => 2,
        ]);
    }

    public function test_exclusive_checkout_no_overlap()
    {
        $guest = \App\Models\User::create([
            'name' => 'Guest 1',
            'email' => 'guest1@example.com',
            'password' => bcrypt('password'),
        ]);

        // Existing booking 20 Aug to 23 Aug (Nights: 20th, 21st, 22nd)
        Booking::create([
            'room_id' => $this->room->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'total_price' => 300000.00,
            'status' => 'Confirmed',
        ]);

        // New booking 23 Aug to 25 Aug (Nights: 23rd, 24th) -> SHOULD NOT OVERLAP with 20-23
        $result = $this->service->checkRoomAvailability(
            $this->room->id,
            '2026-08-23',
            '2026-08-25',
            1
        );

        $this->assertTrue($result['is_available']);
    }

    public function test_date_overlap_detected()
    {
        $guest = \App\Models\User::create([
            'name' => 'Guest 2',
            'email' => 'guest2_overlap@example.com',
            'password' => bcrypt('password'),
        ]);

        // Existing booking 20 Aug to 23 Aug
        Booking::create([
            'room_id' => $this->room->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'total_price' => 300000.00,
            'status' => 'Confirmed',
        ]);

        // New booking 21 Aug to 24 Aug -> OVERLAPS on 21st & 22nd
        $result = $this->service->checkRoomAvailability(
            $this->room->id,
            '2026-08-21',
            '2026-08-24',
            2 // Asking for 2 rooms when 1 is taken out of total_inventory = 2
        );

        $this->assertFalse($result['is_available']);
        $this->assertEquals(1, $result['min_available_inventory']);
    }

    public function test_per_night_inventory_formula()
    {
        $guest1 = \App\Models\User::create([
            'name' => 'Guest 1',
            'email' => 'guest_formula_1@example.com',
            'password' => bcrypt('password'),
        ]);
        $guest2 = \App\Models\User::create([
            'name' => 'Guest 2',
            'email' => 'guest_formula_2@example.com',
            'password' => bcrypt('password'),
        ]);

        // Booking 1: 1 room for 20-22 Aug
        Booking::create([
            'room_id' => $this->room->id,
            'guest_id' => $guest1->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'total_price' => 200000.00,
            'status' => 'Confirmed',
        ]);

        // Temporary Lock: 1 room for 21-23 Aug by guest 2
        RoomLock::create([
            'room_id' => $this->room->id,
            'guest_id' => $guest2->id,
            'check_in' => '2026-08-21',
            'check_out' => '2026-08-23',
            'expires_at' => now()->addMinutes(10),
        ]);

        // Request 1 room for guest 3 for 20-24 Aug
        // On 21st: 1 booking + 1 lock = 2 total occupied => 0 available.
        $result = $this->service->checkRoomAvailability(
            $this->room->id,
            '2026-08-20',
            '2026-08-24',
            1
        );

        $this->assertFalse($result['is_available']);
        $this->assertEquals(0, $result['min_available_inventory']);
    }
}
