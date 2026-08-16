<?php
/**
 * Live AzamPay diagnostic — traces every step of the payment API call
 * and shows exactly where it fails.
 */
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$appName      = env('AZAMPAY_APP_NAME', 'FastNet');
$clientId     = env('AZAMPAY_CLIENT_ID');
$clientSecret = env('AZAMPAY_CLIENT_SECRET');
$storedToken  = env('AZAMPAY_TOKEN');

echo "=== AZAMPAY CONFIG CHECK ===\n";
echo "App Name   : $appName\n";
echo "Client ID  : " . ($clientId ? substr($clientId, 0, 8) . '...' : 'MISSING') . "\n";
echo "Secret     : " . ($clientSecret ? 'SET (' . strlen($clientSecret) . ' chars)' : 'MISSING') . "\n";
echo "Stored Token: " . ($storedToken ? substr($storedToken, 0, 8) . '...' : 'MISSING') . "\n\n";

// STEP 1: Get token
echo "=== STEP 1: GET AUTH TOKEN ===\n";
$token = $storedToken;

if (!$token && $clientSecret) {
    echo "No stored token, requesting new one...\n";
    try {
        $authRes = Http::withoutVerifying()
            ->timeout(10)
            ->post('https://authenticator-sandbox.azampay.co.tz/Applink/GetToken', [
                'appName'      => $appName,
                'clientId'     => $clientId,
                'clientSecret' => $clientSecret,
            ]);

        echo "Auth HTTP Status: " . $authRes->status() . "\n";
        echo "Auth Response: " . $authRes->body() . "\n\n";

        if ($authRes->successful() && isset($authRes['data']['accessToken'])) {
            $token = $authRes['data']['accessToken'];
            echo "Token acquired: " . substr($token, 0, 20) . "...\n\n";
        } else {
            echo "FAILED to get token!\n\n";
        }
    } catch (\Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n\n";
    }
} else if ($token) {
    echo "Using stored token from .env: " . substr($token, 0, 8) . "...\n\n";
}

if (!$token) {
    echo "CANNOT PROCEED: No valid token.\n";
    exit(1);
}

// STEP 2: Test MNO checkout with a test phone number
echo "=== STEP 2: MNO CHECKOUT REQUEST ===\n";
$testPhone  = '255765000000'; // Vodacom Tanzania test number
$testAmount = '1000';
$testRef    = 'TEST-' . time();

echo "Phone: $testPhone\n";
echo "Amount: $testAmount TZS\n";
echo "ExternalId: $testRef\n";
echo "Provider: Mpesa\n\n";

try {
    $checkoutRes = Http::withoutVerifying()
        ->withToken($token)
        ->timeout(15)
        ->post('https://sandbox.azampay.co.tz/api/v1/Checkout/mnocheckout', [
            'accountNumber' => $testPhone,
            'amount'        => $testAmount,
            'currency'      => 'TZS',
            'externalId'    => $testRef,
            'provider'      => 'Mpesa',
        ]);

    echo "Checkout HTTP Status: " . $checkoutRes->status() . "\n";
    echo "Checkout Response: " . $checkoutRes->body() . "\n";

    if ($checkoutRes->status() === 401) {
        echo "\nDIAGNOSIS: Token is expired or invalid!\n";
        echo "ACTION NEEDED: Get a fresh token from AzamPay sandbox dashboard\n";
        echo "  URL: https://developers.azampay.co.tz\n";
    } elseif ($checkoutRes->status() === 400) {
        echo "\nDIAGNOSIS: Bad request — check accountNumber format or amount\n";
    } elseif ($checkoutRes->successful()) {
        echo "\nSUCCESS: Checkout request accepted by AzamPay sandbox\n";
    }
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "\nDIAGNOSIS: Cannot reach AzamPay sandbox servers.\n";
    echo "Check your internet connection or if sandbox.azampay.co.tz is reachable.\n";
}
