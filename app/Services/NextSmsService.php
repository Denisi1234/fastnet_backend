<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NextSmsService
{
    /**
     * Send a single SMS using the NextSMS API.
     *
     * @param string $phoneNumber The recipient's phone number (e.g., 255700000000)
     * @param string $message The text message to send
     * @return bool True if successful, false otherwise
     */
    public static function sendSms(string $phoneNumber, string $message): bool
    {
        $apiKey = env('NEXTSMS_API_KEY'); // The user provided: 7426fb11859a5c62ec120386c8657b50
        $username = env('NEXTSMS_USERNAME');
        $password = env('NEXTSMS_PASSWORD');
        $senderId = env('NEXTSMS_SENDER_ID', 'NEXTSMS');

        if (!$apiKey && (!$username || !$password)) {
            Log::error("NextSMS failed: Missing NEXTSMS_API_KEY or USERNAME/PASSWORD in .env");
            return false;
        }

        // Clean phone number: remove +, spaces, dashes
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Ensure it starts with 255 for TZ if it starts with 0
        if (str_starts_with($phoneNumber, '0')) {
            $phoneNumber = '255' . substr($phoneNumber, 1);
        }

        if (empty($phoneNumber)) {
            Log::warning("NextSMS failed: Invalid phone number provided.");
            return false;
        }

        $url = 'https://messaging-service.co.tz/api/sms/v1/text/single';

        $payload = [
            'from' => $senderId,
            'to'   => $phoneNumber,
            'text' => $message,
        ];

        try {
            $request = Http::withoutVerifying()
                ->timeout(2)
                ->connectTimeout(2)
                ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ]);

            // NextSMS requires Basic Authentication using your Username and Password
            $request->withBasicAuth($username, $password);

            $response = $request->post($url, $payload);

            if ($response->successful()) {
                Log::info("NextSMS sent successfully to {$phoneNumber}");
                return true;
            }

            Log::error("NextSMS failed for {$phoneNumber}: " . $response->body());
            return false;
            
        } catch (\Exception $e) {
            Log::error("NextSMS Exception for {$phoneNumber}: " . $e->getMessage());
            return false;
        }
    }
}
