<?php
$url = 'http://127.0.0.1:8000/api/bookings/revalidate?property_id=1&room_id=1&check_in=2026-08-16&check_out=2026-08-17&guests=1&rooms=1';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Origin: http://127.0.0.1:5500'],
    CURLOPT_TIMEOUT => 15,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP: $code\n";
$data = json_decode($body, true);
echo "valid: " . ($data['valid'] ?? 'NOT SET') . "\n";
echo "error_type: " . ($data['error_type'] ?? 'none') . "\n";
echo "message: " . ($data['message'] ?? 'none') . "\n";
if (isset($data['nightly_rate'])) {
    echo "nightly_rate: " . $data['nightly_rate'] . "\n";
    echo "total_price: " . $data['total_price'] . "\n";
}
