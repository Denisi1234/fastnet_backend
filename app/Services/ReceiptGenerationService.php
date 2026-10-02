<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReceiptGenerationService
{
    public function generate(array $data): array
    {
        $bookingCode = preg_replace('/[^A-Za-z0-9\-]/', '', $data['booking_code'] ?? '');
        $guestName = $data['guest_name'] ?? 'Valued Guest';
        $propertyName = $data['property_name'] ?? 'FastNetStays Property';
        $propertyAddress = $data['property_address'] ?? 'Tanzania';
        $checkIn = $data['check_in'] ?? 'Jan 10, 23';
        $checkOut = $data['check_out'] ?? 'Jan 11, 23';
        $totalPrice = $data['total_price'] ?? 'TSh 105,020';
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
                'qr_payload' => "FASTNETSTAYS-BOOKING:{$bookingCode}|LODGE:{$propertyName}|ROOM:1|GUEST:" . strtoupper($guestName),
            ];
            $rawBytes = json_encode($receiptData, JSON_PRETTY_PRINT);
        }

        $originalSize = strlen($rawBytes);
        $compressedBytes = gzencode($rawBytes, 9);
        $compressedSize = strlen($compressedBytes);
        $savedRatio = $originalSize > 0 ? round((1 - ($compressedSize / $originalSize)) * 100, 2) . '%' : '0%';

        $fileName = "receipts/{$bookingCode}.pdf";

        try {
            // Save directly to local disk (Railway Persistent Volume) instead of Supabase
            \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $compressedBytes);

            $publicUrl = env('APP_URL') . '/storage/' . $fileName;

            return [
                'status' => 'success',
                'booking_code' => $bookingCode,
                'receipt_url' => $publicUrl,
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
