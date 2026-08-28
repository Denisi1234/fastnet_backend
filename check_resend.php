<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$apiKey = env('RESEND_API_KEY');
echo "API Key: " . substr($apiKey, 0, 10) . "...\n";

// Test with bookings@fastnetstays.com
$payload = [
    'from' => 'FastNetStays <bookings@fastnetstays.com>',
    'to' => ['dm328434@gmail.com'],
    'subject' => 'FastNetStays Booking Confirmation Test',
    'html' => '<h1>Your booking at Sunrise Lodge is confirmed!</h1>'
];

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);
$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Attempt 1 (bookings@fastnetstays.com):\n";
echo "HTTP Status: " . $httpCode . "\n";
echo "Response: " . $raw . "\n\n";

// Test with onboarding@resend.dev
$payload2 = [
    'from' => 'FastNetStays <onboarding@resend.dev>',
    'to' => ['dm328434@gmail.com'],
    'subject' => 'FastNetStays Booking Confirmation Test (Fallback)',
    'html' => '<h1>Your booking at Sunrise Lodge is confirmed! (Fallback)</h1>'
];

$ch2 = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload2),
]);
$raw2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "Attempt 2 (onboarding@resend.dev):\n";
echo "HTTP Status: " . $httpCode2 . "\n";
echo "Response: " . $raw2 . "\n";
