<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$token = env('AZAMPAY_TOKEN');
echo "Testing AzamPay MNO Checkout with detailed response inspection:\n";

$payload = [
    'accountNumber' => '0765000000', // Try 07xx and 2557xx
    'amount'        => '1000',
    'currency'      => 'TZS',
    'externalId'    => 'TEST_' . time(),
    'provider'      => 'Mpesa',
];

$urls = [
    'https://sandbox.azampay.co.tz/azampay/mno/post',
    'https://sandbox.azampay.co.tz/azampay/mno/checkout',
    'https://sandbox.azampay.co.tz/api/v1/Checkout/mnocheckout'
];

foreach ($urls as $url) {
    echo "\nTrying POST to $url ...\n";
    $r = Http::withoutVerifying()
        ->withToken($token)
        ->withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
        ->post($url, $payload);
    
    echo "Status: " . $r->status() . "\n";
    echo "Headers:\n";
    print_r($r->headers());
    echo "Body: " . $r->body() . "\n";
}
