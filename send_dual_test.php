<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$apiKey = env('RESEND_API_KEY');

$recipients = ['dm328434@gmail.com', 'mahenge328432@gmail.com'];

foreach ($recipients as $email) {
    echo "Sending to {$email}...\n";
    $guest = (object)[
        'name' => 'Denis Mahenge',
        'email' => $email
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

    $res = \App\Services\ResendMailService::sendBookingConfirmation($guest, $booking, $property);
    echo "Result for {$email}: " . ($res ? "SUCCESS" : "FAILED") . "\n\n";
}
