<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Property;
use App\Models\Room;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MasterOtaSearchEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private Property $lodgeA;
    private Property $lodgeB;
    private Room $roomA;
    private Room $roomB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::create([
            'name' => 'Safari Host',
            'email' => 'host_ota@example.com',
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        // Lodge A: Single room with total_inventory = 1
        $this->lodgeA = Property::create([
            'name' => 'Lodge A Serengeti',
            'city' => 'Serengeti',
            'area' => 'National Park',
            'price_per_night' => 100000.00,
            'host_id' => $this->host->id,
            'status' => 'Active',
        ]);

        $this->roomA = Room::create([
            'property_id' => $this->lodgeA->id,
            'room_number' => '101',
            'status' => 'available',
            'price' => 100000.00,
            'capacity' => 2,
            'total_inventory' => 1,
        ]);

        // Lodge B: Multi room with total_inventory = 2
        $this->lodgeB = Property::create([
            'name' => 'Lodge B Ngorongoro',
            'city' => 'Ngorongoro',
            'area' => 'Crater',
            'price_per_night' => 150000.00,
            'host_id' => $this->host->id,
            'status' => 'Active',
        ]);

        $this->roomB = Room::create([
            'property_id' => $this->lodgeB->id,
            'room_number' => '201',
            'status' => 'available',
            'price' => 150000.00,
            'capacity' => 4,
            'total_inventory' => 2,
        ]);
    }

    public function test_property_hidden_when_all_rooms_fully_booked_for_stay_dates()
    {
        // Guest 1 reserves Lodge A room from 20 Aug to 23 Aug 2026
        $guest = User::create([
            'name' => 'Guest One',
            'email' => 'guest1_ota@example.com',
            'password' => bcrypt('password'),
        ]);

        Booking::create([
            'room_id' => $this->roomA->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'total_price' => 303000.00,
            'status' => 'Confirmed',
        ]);

        // Customer searches for 20 Aug to 23 Aug
        $response = $this->getJson('/api/properties?check_in=2026-08-20&check_out=2026-08-23&guests=2');

        $response->assertStatus(200);
        $data = $response->json();

        // Lodge A MUST NOT appear because it has 0 inventory for stay dates
        $propertyIds = array_column($data, 'id');
        $this->assertNotContains($this->lodgeA->id, $propertyIds);
        $this->assertContains($this->lodgeB->id, $propertyIds);
    }

    public function test_property_appears_when_search_dates_do_not_overlap_checkout()
    {
        $guest = User::create([
            'name' => 'Guest One',
            'email' => 'guest1_no_overlap@example.com',
            'password' => bcrypt('password'),
        ]);

        Booking::create([
            'room_id' => $this->roomA->id,
            'guest_id' => $guest->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'total_price' => 303000.00,
            'status' => 'Confirmed',
        ]);

        // Customer searches for 23 Aug to 26 Aug (Check-in on 23 Aug = Checkout of previous stay) -> NO OVERLAP
        $response = $this->getJson('/api/properties?check_in=2026-08-23&check_out=2026-08-26&guests=2');

        $response->assertStatus(200);
        $data = $response->json();

        $propertyIds = array_column($data, 'id');
        $this->assertContains($this->lodgeA->id, $propertyIds);
    }

    public function test_multi_room_quantity_request_hides_insufficient_inventory()
    {
        // Customer requests 2 rooms for Lodge A (which has total_inventory = 1)
        $response = $this->getJson('/api/properties?check_in=2026-09-01&check_out=2026-09-03&rooms=2&guests=2');

        $response->assertStatus(200);
        $data = $response->json();

        $propertyIds = array_column($data, 'id');
        $this->assertNotContains($this->lodgeA->id, $propertyIds);
        $this->assertContains($this->lodgeB->id, $propertyIds);
    }

    public function test_exceeding_guest_capacity_hides_property()
    {
        // Customer requests 5 guests (Lodge A capacity = 2, Lodge B capacity = 4)
        $response = $this->getJson('/api/properties?check_in=2026-09-01&check_out=2026-09-03&guests=5');

        $response->assertStatus(200);
        $data = $response->json();

        $propertyIds = array_column($data, 'id');
        $this->assertNotContains($this->lodgeA->id, $propertyIds);
        $this->assertNotContains($this->lodgeB->id, $propertyIds);
    }
}
