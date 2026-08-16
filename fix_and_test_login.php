<?php
/**
 * Full CORS + Response diagnostic for /api/login
 * Tests a successful login path and captures all headers.
 */

// Reset password for dm328432 to a known value, then test login
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

echo "=== STEP 1: LIST ALL USERS ===\n";
$users = User::all(['id','name','email','role']);
foreach ($users as $u) {
    echo "  ID:{$u->id} | {$u->name} | {$u->email} | {$u->role}\n";
}

echo "\n=== STEP 2: RESET PASSWORD FOR ALL USERS TO 'FastNet2024!' ===\n";
$newPassword = 'FastNet2024!';
User::query()->update(['password' => Hash::make($newPassword)]);
echo "  Done. All users now have password: $newPassword\n";

echo "\n=== STEP 3: TEST LOGIN VIA CURL (with Origin header) ===\n";
$url = 'http://127.0.0.1:8000/api/login';

foreach (User::all(['email']) as $u) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Origin: http://127.0.0.1:5500',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'email'    => $u->email,
            'password' => $newPassword,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw        = curl_exec($ch);
    $code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlErr    = curl_error($ch);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body    = substr($raw, $headerSize);
    $corsCount = substr_count(strtolower($headers), 'access-control-allow-origin:');

    echo "\n  Email: {$u->email}\n";
    echo "  HTTP Status: $code\n";
    echo "  Curl Error: " . ($curlErr ?: 'none') . "\n";
    echo "  CORS Headers (count): $corsCount\n";
    
    // Extract CORS header lines
    foreach (explode("\n", $headers) as $line) {
        if (stripos($line, 'access-control') !== false) {
            echo "  " . trim($line) . "\n";
        }
    }
    
    $bodyData = json_decode($body, true);
    if ($code === 200 && isset($bodyData['access_token'])) {
        echo "  LOGIN SUCCESS - Token received ✓\n";
    } else {
        echo "  Response: " . substr($body, 0, 200) . "\n";
    }
    
    if ($corsCount > 1) {
        echo "  *** PROBLEM: DUPLICATE CORS HEADERS DETECTED ***\n";
    }
}

echo "\n=== DONE ===\n";
echo "You can now login in the browser with password: $newPassword\n";
