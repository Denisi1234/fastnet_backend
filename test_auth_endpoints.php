<?php
function testPost($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Origin: http://127.0.0.1:5500'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    echo "URL: $url\nHTTP Status: $code\nCurl Error: $err\nResponse: $res\n\n";
}

echo "=== TEST 1: Login with invalid password ===\n";
testPost('http://127.0.0.1:8000/api/login', [
    'email' => 'mahenge328432@gmail.com',
    'password' => 'wrongpassword'
]);

echo "=== TEST 2: Register with duplicate email ===\n";
testPost('http://127.0.0.1:8000/api/register', [
    'name' => 'Duplicate Test',
    'email' => 'mahenge328432@gmail.com',
    'password' => 'password123'
]);

echo "=== TEST 3: Easy-Auth endpoint ===\n";
testPost('http://127.0.0.1:8000/api/easy-auth', [
    'login' => 'mahenge328432@gmail.com'
]);
