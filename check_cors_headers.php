#!/usr/bin/env php
<?php
/**
 * Capture full response headers from /api/login to detect CORS duplicate headers.
 * Try multiple passwords to find the right one.
 */

$url = 'http://127.0.0.1:8000/api/login';

$testCases = [
    ['email' => 'mahenge328432@gmail.com', 'password' => 'Mahenge328432'],
    ['email' => 'mahenge328432@gmail.com', 'password' => 'password'],
    ['email' => 'mahenge328432@gmail.com', 'password' => 'mahenge328432'],
    ['email' => 'mahenge328432@gmail.com', 'password' => 'Password123'],
    ['email' => 'dm328432@gmail.com', 'password' => 'password'],
    ['email' => 'dm328432@gmail.com', 'password' => 'dm328432'],
    ['email' => 'dm328432@gmail.com', 'password' => 'Password123'],
];


$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$curlErr  = curl_error($ch);
curl_close($ch);

$headers = substr($raw, 0, $headerSize);
$body    = substr($raw, $headerSize);

echo "=== HTTP STATUS ===\n";
echo "Code: $httpCode\n";
echo "Curl Error: " . ($curlErr ?: 'none') . "\n\n";

echo "=== RESPONSE HEADERS ===\n";
echo $headers . "\n";

echo "=== RESPONSE BODY ===\n";
echo $body . "\n";

// Count Access-Control-Allow-Origin headers
$corsCount = substr_count(strtolower($headers), 'access-control-allow-origin:');
echo "\n=== CORS DIAGNOSIS ===\n";
echo "Access-Control-Allow-Origin header count: $corsCount\n";
if ($corsCount > 1) {
    echo "PROBLEM FOUND: DUPLICATE CORS HEADERS! This blocks browsers from processing the response.\n";
} elseif ($corsCount === 1) {
    echo "CORS header present exactly once. CORS looks correct.\n";
} else {
    echo "WARNING: No Access-Control-Allow-Origin header found!\n";
}
