<?php
/**
 * Simulate what the rooms page actually receives from the API.
 */
$url = 'http://127.0.0.1:8000/api/properties/1?check_in=2026-08-16&check_out=2026-08-17&guests=1&rooms=1';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Origin: http://127.0.0.1:5500',
    ],
    CURLOPT_TIMEOUT => 15,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP: $code\n";
echo "Error: $err\n\n";

$data = json_decode($body, true);
if (!$data) {
    echo "PARSE ERROR: $body\n";
    exit;
}

echo "Property: " . $data['name'] . "\n";
echo "Rooms count: " . count($data['rooms'] ?? []) . "\n\n";

foreach ($data['rooms'] ?? [] as $room) {
    echo "--- Room ID: {$room['id']} ---\n";
    echo "  room_number: " . ($room['room_number'] ?? 'NULL') . "\n";
    echo "  status:      " . ($room['status'] ?? 'NULL') . "\n";
    echo "  price:       " . ($room['price'] ?? 'NULL') . "\n";
    echo "  capacity:    " . ($room['capacity'] ?? 'NULL') . "\n";
    echo "  is_available: " . (isset($room['is_available']) ? var_export($room['is_available'], true) : 'NOT SET') . "\n";
    echo "  is_locked:   " . (isset($room['is_locked']) ? var_export($room['is_locked'], true) : 'NOT SET') . "\n";
    echo "  meets_capacity: " . (isset($room['meets_capacity']) ? var_export($room['meets_capacity'], true) : 'NOT SET') . "\n";
    echo "  unavailability_reason: " . ($room['unavailability_reason'] ?? 'none') . "\n";
}
