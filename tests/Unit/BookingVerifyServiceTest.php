<?php

namespace Tests\Unit;

use App\Services\BookingVerifyService;
use Tests\TestCase;

class BookingVerifyServiceTest extends TestCase
{
    private BookingVerifyService $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new BookingVerifyService();
    }

    public function test_sign_is_deterministic_and_url_safe(): void
    {
        $a = $this->signer->sign('FN-1234');
        $b = $this->signer->sign('FN-1234');
        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a);
    }

    public function test_check_accepts_valid_and_rejects_forgery(): void
    {
        $code = 'FN-5678';
        $sig = $this->signer->sign($code);
        $this->assertTrue($this->signer->check($code, $sig));
        $this->assertFalse($this->signer->check($code, 'deadbeefdeadbeef'));
        $this->assertFalse($this->signer->check('FN-9999', $sig));
        $this->assertFalse($this->signer->check('', $sig));
        $this->assertFalse($this->signer->check($code, ''));
    }

    public function test_verify_url_points_at_public_verify_page(): void
    {
        $url = $this->signer->verifyUrl('FN-1234');
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('/verify-booking/FN-1234?s=', $url);
        $this->assertStringContainsString($this->signer->sign('FN-1234'), $url);
    }

    public function test_slim_payload_exposes_no_contact_details(): void
    {
        $booking = new \App\Models\Booking([
            'booking_code' => 'FN-1',
            'status' => 'Confirmed',
            'payment_status' => 'paid',
            'check_in' => '2026-01-05',
            'check_out' => '2026-01-07',
        ]);
        $payload = $this->signer->slimPayload($booking);
        $this->assertSame('FN-1', $payload['booking_code']);
        $this->assertArrayNotHasKey('payment_phone', $payload);
        $this->assertArrayNotHasKey('payment_reference', $payload);
        $this->assertArrayNotHasKey('guest_id', $payload);
    }
}
