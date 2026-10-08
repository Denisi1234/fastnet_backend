<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReceiptGenerationService
{
    public function generate(array $data): array
    {
        $bookingCode = preg_replace('/[^A-Za-z0-9\-]/', '', $data['booking_code'] ?? '');
        // A receipt is a financial document. It previously defaulted to
        // invented values ('Valued Guest', 'TSh 105,020', 'Jan 10, 23'), so a
        // request missing fields produced a convincing but entirely fictional
        // bill. Require the essentials and report what is missing instead.
        $required = ['guest_name', 'property_name', 'check_in', 'check_out', 'total_price'];
        $missing = array_values(array_filter($required, fn ($k) => !isset($data[$k]) || $data[$k] === ''));

        if ($missing) {
            return [
                'status' => 'error',
                'message' => 'Receipt cannot be generated: missing ' . implode(', ', $missing) . '.',
            ];
        }

        $guestName = (string) $data['guest_name'];
        $propertyName = (string) $data['property_name'];
        $propertyAddress = (string) ($data['property_address'] ?? '');
        $checkIn = (string) $data['check_in'];
        $checkOut = (string) $data['check_out'];
        $totalPrice = (string) $data['total_price'];
        $roomNumber = (string) ($data['room_number'] ?? '');
        $guestPreferences = $data['guest_preferences'] ?? [
            'smoking_preference' => 'Non-smoking',
            'bed_preference' => 'No preference',
            'room_type' => 'No preference',
            'dietary_requirements' => 'None',
        ];

        if (!empty($data['pdf_base64'])) {
            $rawBytes = base64_decode($data['pdf_base64']);
        } else {
            $receiptData = [
                'type' => 'FASTNET_E_RECEIPT_V1',
                'booking_code' => $bookingCode,
                'guest_name' => strtoupper($guestName),
                'property_name' => $propertyName,
                'property_address' => $propertyAddress,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'total_price' => $totalPrice,
                'guest_preferences' => $guestPreferences,
                'issued_at' => date('Y-m-d H:i:s'),
                // Signed verification URL any phone camera can open. Callers
                // may pass a precomputed verify_url; otherwise sign here so
                // emailed receipts carry the same live QR as the app.
                'qr_payload' => $data['verify_url'] ?? (new BookingVerifyService())->verifyUrl($bookingCode),
            ];
            $rawBytes = json_encode($receiptData, JSON_PRETTY_PRINT);
        }

        $originalSize = strlen($rawBytes);
        $compressedBytes = gzencode($rawBytes, 9);
        $compressedSize = strlen($compressedBytes);
        $savedRatio = $originalSize > 0 ? round((1 - ($compressedSize / $originalSize)) * 100, 2) . '%' : '0%';

        // The payload is gzipped JSON, not PDF bytes. It was written to a
        // ".pdf" filename, so the returned link was a file no PDF reader could
        // open. Name it for what it actually is.
        $fileName = "receipts/{$bookingCode}.receipt.json.gz";

        try {
            // Save directly to local disk (Railway Persistent Volume) instead of Supabase
            \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $compressedBytes);

            $publicUrl = env('APP_URL') . '/storage/' . $fileName;

            return [
                'status' => 'success',
                'booking_code' => $bookingCode,
                'receipt_url' => $publicUrl,
                'format' => 'application/gzip',
                'content_description' => 'Gzipped JSON receipt payload',
                'original_size_bytes' => $originalSize,
                'compressed_size_bytes' => $compressedSize,
                'compression_ratio' => $savedRatio,
            ];
        } catch (\Exception $e) {
            Log::error('Backend Local Receipt Upload Failed: ' . $e->getMessage());

            return [
                'status' => 'error',
                'booking_code' => $bookingCode,
                'receipt_url' => null,
                'original_size_bytes' => $originalSize,
                'compressed_size_bytes' => $compressedSize,
                'compression_ratio' => $savedRatio,
                'notice' => 'Failed to save receipt to local storage.'
            ];
        }
    }
}
