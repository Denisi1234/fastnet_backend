<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Simulate exactly what the show() API endpoint does
$prop = App\Models\Property::with([
    'rooms:id,property_id,room_number,room_type_id,price,capacity,status,amenities,photos,floor,max_adults,max_children,bed_configuration,number_of_beds,room_size',
])->find(11);

$arr = $prop->toArray();

echo "=== ROOMS IN API RESPONSE ===" . PHP_EOL;
echo "rooms count: " . count($arr['rooms']) . PHP_EOL;
foreach ($arr['rooms'] as $r) {
    echo PHP_EOL . "Room: " . $r['room_number'] . PHP_EOL;
    echo "  photos key exists: " . (array_key_exists('photos', $r) ? 'YES' : 'NO') . PHP_EOL;
    echo "  photos value: " . json_encode($r['photos']) . PHP_EOL;
    echo "  images key exists: " . (array_key_exists('images', $r) ? 'YES' : 'NO') . PHP_EOL;
    echo "  images count: " . count($r['images'] ?? []) . PHP_EOL;
    if (!empty($r['images'])) {
        echo "  first image: " . json_encode($r['images'][0]) . PHP_EOL;
    }
}
