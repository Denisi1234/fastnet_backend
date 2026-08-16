<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== ALL BOOKINGS FOR ROOM ID 1 ===\n";
$bookings = DB::table('bookings')->where('room_id', 1)->get();
echo "Total bookings: " . $bookings->count() . "\n\n";
foreach ($bookings as $b) {
    foreach ((array)$b as $col => $val) {
        echo "  $col: " . var_export($val, true) . "\n";
    }
    echo "\n";
}

echo "\n=== BOOKING OVERLAP CHECK for 2026-08-16 to 2026-08-17 ===\n";
$checkIn  = '2026-08-16';
$checkOut = '2026-08-17';
$conflicts = DB::table('bookings')
    ->where('room_id', 1)
    ->where('status', '!=', 'Cancelled')
    ->where('check_in', '<', $checkOut)
    ->where('check_out', '>', $checkIn)
    ->get();
echo "Conflicting bookings: " . $conflicts->count() . "\n";
foreach ($conflicts as $b) {
    echo "  ID:{$b->id} | check_in:{$b->check_in} | check_out:{$b->check_out} | status:{$b->status}\n";
}
