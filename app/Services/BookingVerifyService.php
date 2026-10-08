<?php

namespace App\Services;

/**
 * Signed QR verification for printed booking confirmations.
 *
 * The receipt QR encodes a public URL any phone camera can open:
 *   {FRONTEND_URL}/verify-booking/{booking_code}?s={signature}
 * The HMAC signature (keyed by the app key, never shipped to clients)
 * proves the URL was issued by us, so forged lookalike QRs fail closed.
 * The signature itself is the auth — the verify page and endpoint stay
 * public, but only ever expose what is already printed on the receipt
 * (no guest emails, phones, or payment details).
 */
class BookingVerifyService
{
    public function sign(string $bookingCode): string
    {
        $code = trim($bookingCode);
        return substr(hash_hmac('sha256', $code, (string) config('app.key')), 0, 16);
    }

    public function verifyUrl(string $bookingCode): string
    {
        $base = rtrim((string) env('FRONTEND_URL', 'https://fastnetstays.com'), '/');
        return $base . '/verify-booking/' . urlencode(trim($bookingCode)) . '?s=' . $this->sign($bookingCode);
    }

    public function check(string $bookingCode, string $signature): bool
    {
        $code = trim($bookingCode);
        $sig = trim($signature);
        if ($code === '' || $sig === '') {
            return false;
        }
        return hash_equals($this->sign($code), $sig);
    }

    /**
     * Public-safe booking summary: mirror of the printed receipt only.
     */
    public function slimPayload($booking): array
    {
        $room = $booking->room;
        $property = $room?->property;
        $guest = $booking->guest;
        return [
            'booking_code' => $booking->booking_code,
            'status' => $booking->status,
            'payment_status' => $booking->payment_status ?? null,
            'check_in' => $booking->check_in,
            'check_out' => $booking->check_out,
            'guest_name' => $guest?->name,
            'property_name' => $property?->name,
            'property_city' => $property?->city ?? null,
            'property_area' => $property?->area ?? null,
            'room_number' => $room?->room_number ?? null,
            'room_type' => $room?->room_type ?? null,
        ];
    }
}
