<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Property;
use App\Models\Room;
use App\Models\Booking;

/**
 * OTA Search Engine Feature Tests
 *
 * Runs against the real Supabase PostgreSQL database.
 * Test data is scoped to unique city names (TestCity_<unique>) to avoid
 * interfering with real production data. All test records are cleaned up
 * in tearDown.
 */
class MasterOtaSearchEngineTest extends TestCase
{
    private User $host;
    private Property $lodgeA;
    private Property $lodgeB;
    private Room $roomA;
    private Room $roomB;

    // Unique tag to isolate test data from real data
    private string $testTag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testTag = 'TestCity_' . uniqid();

        $this->host = User::create([
            'name'     => 'Safari Host ' . $this->testTag,
            'email'    => 'host_ota_' . $this->testTag . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'owner',
            'status'   => 'Active',
        ]);

        // Lodge A: Single room with total_inventory = 1
        $this->lodgeA = Property::create([
            'name'            => 'Lodge A ' . $this->testTag,
            'city'            => $this->testTag,
            'area'            => 'Test Area',
            'price_per_night' => 100000.00,
            'host_id'         => $this->host->id,
            'status'          => 'Active',
        ]);

        $this->roomA = Room::create([
            'property_id'     => $this->lodgeA->id,
            'room_number'     => '101',
            'status'          => 'available',
            'price'           => 100000.00,
            'capacity'        => 2,
            'total_inventory' => 1,
        ]);

        // Lodge B: Multi room with total_inventory = 2
        $this->lodgeB = Property::create([
            'name'            => 'Lodge B ' . $this->testTag,
            'city'            => $this->testTag,
            'area'            => 'Test Crater',
            'price_per_night' => 150000.00,
            'host_id'         => $this->host->id,
            'status'          => 'Active',
        ]);

        $this->roomB = Room::create([
            'property_id'     => $this->lodgeB->id,
            'room_number'     => '201',
            'status'          => 'available',
            'price'           => 150000.00,
            'capacity'        => 4,
            'total_inventory' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up all test data created by this test run
        Booking::whereIn('room_id', [$this->roomA->id, $this->roomB->id])->forceDelete();
        Room::whereIn('id', [$this->roomA->id, $this->roomB->id])->forceDelete();
        Property::whereIn('id', [$this->lodgeA->id, $this->lodgeB->id])->forceDelete();
        User::where('id', $this->host->id)->forceDelete();
        parent::tearDown();
    }

    public function test_property_hidden_when_all_rooms_fully_booked_for_stay_dates()
    {
        $guest = User::create([
            'name'     => 'Guest One ' . $this->testTag,
            'email'    => 'guest1_' . $this->testTag . '@example.com',
            'password' => bcrypt('password'),
        ]);

        Booking::create([
            'room_id'     => $this->roomA->id,
            'guest_id'    => $guest->id,
            'check_in'    => '2026-08-20',
            'check_out'   => '2026-08-23',
            'total_price' => 303000.00,
            'status'      => 'Confirmed',
        ]);

        // Scope search to unique test city so only test lodges appear
        $response = $this->getJson(
            '/api/properties?check_in=2026-08-20&check_out=2026-08-23&guests=2&q=' . urlencode($this->testTag)
        );

        $response->assertStatus(200);
        $responseData = $response->json();
        $properties   = isset($responseData['data']) ? $responseData['data'] : $responseData;

        $propertyIds = array_column($properties, 'id');
        $this->assertNotContains($this->lodgeA->id, $propertyIds);
        $this->assertContains($this->lodgeB->id, $propertyIds);

        // Clean up guest
        $guest->forceDelete();
    }

    public function test_property_appears_when_search_dates_do_not_overlap_checkout()
    {
        $guest = User::create([
            'name'     => 'Guest Two ' . $this->testTag,
            'email'    => 'guest2_' . $this->testTag . '@example.com',
            'password' => bcrypt('password'),
        ]);

        Booking::create([
            'room_id'     => $this->roomA->id,
            'guest_id'    => $guest->id,
            'check_in'    => '2026-08-20',
            'check_out'   => '2026-08-23',
            'total_price' => 303000.00,
            'status'      => 'Confirmed',
        ]);

        // Check-in on 23 Aug = Checkout of previous booking → NO OVERLAP
        $response = $this->getJson(
            '/api/properties?check_in=2026-08-23&check_out=2026-08-26&guests=2&q=' . urlencode($this->testTag)
        );

        $response->assertStatus(200);
        $responseData = $response->json();
        $properties   = isset($responseData['data']) ? $responseData['data'] : $responseData;

        $propertyIds = array_column($properties, 'id');
        $this->assertContains($this->lodgeA->id, $propertyIds);

        $guest->forceDelete();
    }

    public function test_multi_room_quantity_request_hides_insufficient_inventory()
    {
        // Lodge A has total_inventory=1 → can't satisfy 2 rooms
        $response = $this->getJson(
            '/api/properties?check_in=2026-09-01&check_out=2026-09-03&rooms=2&guests=2&q=' . urlencode($this->testTag)
        );

        $response->assertStatus(200);
        $responseData = $response->json();
        $properties   = isset($responseData['data']) ? $responseData['data'] : $responseData;

        $propertyIds = array_column($properties, 'id');
        $this->assertNotContains($this->lodgeA->id, $propertyIds);
        $this->assertContains($this->lodgeB->id, $propertyIds);
    }

    public function test_exceeding_guest_capacity_hides_property()
    {
        // Lodge A capacity=2, Lodge B capacity=4 → neither fits 5 guests
        $response = $this->getJson(
            '/api/properties?check_in=2026-09-01&check_out=2026-09-03&guests=5&q=' . urlencode($this->testTag)
        );

        $response->assertStatus(200);
        $responseData = $response->json();
        $properties   = isset($responseData['data']) ? $responseData['data'] : $responseData;

        $propertyIds = array_column($properties, 'id');
        $this->assertNotContains($this->lodgeA->id, $propertyIds);
        $this->assertNotContains($this->lodgeB->id, $propertyIds);
    }
}

