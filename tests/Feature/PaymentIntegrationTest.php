<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Property;
use App\Models\Room;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private User $guest;
    private Property $property;
    private Room $room;
    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::create([
            'name' => 'Host User',
            'email' => 'host_payment@example.com',
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        $this->guest = User::create([
            'name' => 'Guest User',
            'email' => 'guest_payment@example.com',
            'password' => bcrypt('password'),
            'role' => 'customer',
        ]);

        $this->property = Property::create([
            'name' => 'Zanzibar Beach Resort',
            'city' => 'Zanzibar',
            'area' => 'Nungwi',
            'price_per_night' => 200000.00,
            'host_id' => $this->host->id,
            'status' => 'Active',
        ]);

        $this->room = Room::create([
            'property_id' => $this->property->id,
            'room_number' => '301',
            'status' => 'available',
            'price' => 200000.00,
            'capacity' => 2,
            'total_inventory' => 2,
        ]);

        // Create booking with authoritative price: 200,000 * 2 nights + 18% VAT (72,000) = 472,000 TZS
        $this->booking = Booking::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-03',
            'total_price' => 472000.00,
            'status' => 'Pending',
            'payment_status' => 'pending',
            'booking_code' => 'BKTESTPAY100',
        ]);
    }

    public function test_payment_checkout_uses_backend_authoritative_total()
    {
        $response = $this->actingAs($this->guest)
            ->postJson('/api/payments/checkout', [
                'booking_code' => $this->booking->booking_code,
                'gateway' => 'AzamPay M-Pesa',
                'phone_number' => '255754123456',
            ]);

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(472000.00, $data['amount']);
        $this->assertEquals('TZS', $data['currency']);
        $this->assertEquals('pending', $data['status']);

        // Assert payment record created with authoritative DB amount
        $payment = Payment::where('booking_id', $this->booking->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(472000.00, (float)$payment->amount);
    }

    public function test_webhook_verifies_matching_payment_amount()
    {
        // Successful webhook with matching amount
        $response = $this->postJson('/api/payments/webhook', [
            'booking_code' => $this->booking->booking_code,
            'reference' => 'TX-AZAM-MATCH100',
            'status' => 'successful',
            'amount' => 472000.00,
            'currency' => 'TZS',
        ]);

        $response->assertStatus(200);

        $this->booking->refresh();
        $this->assertEquals('paid', $this->booking->payment_status);
        $this->assertEquals('Confirmed', $this->booking->status);
    }

    public function test_webhook_rejects_underpaid_tampered_amount()
    {
        // Webhook sends payment status successful, BUT underpaid amount (e.g. 10.00 TZS instead of 472,000.00 TZS)
        $response = $this->postJson('/api/payments/webhook', [
            'booking_code' => $this->booking->booking_code,
            'reference' => 'TX-AZAM-UNDERPAID',
            'status' => 'successful',
            'amount' => 10.00,
            'currency' => 'TZS',
        ]);

        $response->assertStatus(400);

        $this->booking->refresh();
        $this->assertEquals('amount_mismatch', $this->booking->payment_status);
        $this->assertEquals('Pending Verification', $this->booking->status);
    }

    public function test_webhook_handles_failed_and_cancelled_payment_states()
    {
        $response = $this->postJson('/api/payments/webhook', [
            'booking_code' => $this->booking->booking_code,
            'reference' => 'TX-AZAM-FAIL',
            'status' => 'failed',
        ]);

        $response->assertStatus(200);

        $this->booking->refresh();
        $this->assertEquals('failed', $this->booking->payment_status);
        $this->assertEquals('Cancelled', $this->booking->status);
    }
}
