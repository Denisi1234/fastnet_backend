<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== SENDING CONFIRMATION NOTIFICATIONS ===\n";

$guest = (object)[
    'name' => 'Denis Mahenge',
    'email' => 'dm328434@gmail.com',
    'phone_number' => '0624105850'
];

$booking = (object)[
    'id' => 1,
    'booking_code' => 'BKKSNA8CSN',
    'check_in' => '2026-08-25',
    'check_out' => '2026-08-28',
    'total_price' => 105000
];

$property = (object)[
    'name' => 'Sunrise Lodge',
    'address' => 'Zanzibar, Tanzania'
];

echo "1. Sending Resend Confirmation Email to dm328434@gmail.com...\n";
$emailSuccess = \App\Services\ResendMailService::sendBookingConfirmation($guest, $booking, $property);
echo "Email Result: " . ($emailSuccess ? "SUCCESS (Sent via Resend)" : "FAILED") . "\n";

echo "2. Sending NextSMS to 0624105850...\n";
$smsMessage = "FastNetStays: Hi {$guest->name}! Your booking at {$property->name} is confirmed. Check-in: 25 Aug 2026, Check-out: 28 Aug 2026. Ref: {$booking->booking_code}. PIN: 3947. Total: TSh 105,000. Enjoy your stay!";
$smsSuccess = \App\Services\NextSmsService::sendSms('0624105850', $smsMessage);
echo "SMS Result: " . ($smsSuccess ? "SUCCESS (Sent via NextSMS)" : "FAILED") . "\n";

echo "=== DONE ===\n";
