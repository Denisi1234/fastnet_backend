<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$token = env('AZAMPAY_TOKEN');
$clientId = env('AZAMPAY_CLIENT_ID');
$clientSecret = env('AZAMPAY_CLIENT_SECRET');
$appName = env('AZAMPAY_APP_NAME', 'FastNet');

echo "=== STEP 1: Re-authenticate (get fresh token) ===\n";
$freshToken = null;
try {
    $authRes = Http::withoutVerifying()->timeout(10)
        ->post('https://authenticator-sandbox.azampay.co.tz/Applink/GetToken', [
            'appName'      => $appName,
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret,
        ]);
    echo "Status: " . $authRes->status() . "\n";
    echo "Body: " . $authRes->body() . "\n\n";
    if ($authRes->successful() && isset($authRes['data']['accessToken'])) {
        $freshToken = $authRes['data']['accessToken'];
        echo "Fresh token obtained: " . substr($freshToken, 0, 20) . "...\n\n";
    }
} catch (\Exception $e) {
    echo "Auth exception: " . $e->getMessage() . "\n\n";
}

$useToken = $freshToken ?? $token;

echo "=== STEP 2: Try all known endpoint formats ===\n";
$endpoints = [
    'https://sandbox.azampay.co.tz/api/v1/Checkout/mnocheckout',
    'https://sandbox.azampay.co.tz/azampay/mno/post',
];

$payload = [
    'accountNumber' => '255765000000',
    'amount'        => '1000',
    'currency'      => 'TZS',
    'externalId'    => 'DIAG-' . time(),
    'provider'      => 'Mpesa',
];

foreach ($endpoints as $url) {
    try {
        $r = Http::withoutVerifying()->withToken($useToken)->timeout(10)->post($url, $payload);
        echo "\n" . $url . "\n";
        echo "  Status: " . $r->status() . "\n";
        echo "  Body: " . ($r->body() ?: '(empty)') . "\n";
    } catch (\Exception $e) {
        echo "\n" . $url . " => ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n=== DIAGNOSIS ===\n";
echo "Stored token in .env: " . ($token ? substr($token, 0, 8) . '...' : 'NOT SET') . "\n";
echo "Fresh token:          " . ($freshToken ? substr($freshToken, 0, 8) . '...' : 'FAILED TO GET') . "\n";
echo "\nIf all endpoints return 404:\n";
echo "  - The sandbox URL has changed or requires registration at azampay.co.tz\n";
echo "  - Or your account is not whitelisted on sandbox yet\n";
echo "\nIf 401 Unauthorized:\n";
echo "  - Token in .env is expired\n";
echo "  - Update AZAMPAY_TOKEN in .env\n";
