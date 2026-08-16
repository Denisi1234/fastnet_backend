<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Property;
use App\Models\Room;
use App\Models\Booking;

echo "=== SUNRISE LODGE INVESTIGATION ===\n\n";

$props = Property::where('name', 'like', '%SUNRISE%')->orWhere('name', 'like', '%Sunrise%')->orWhere('id', 1)->get();
if ($props->isEmpty()) {
    echo "No property found with 'Sunrise' in name. Searching all properties...\n";
    $props = Property::all(['id','name','city']);
    foreach ($props as $p) {
        echo "  " . $p->id . " | " . $p->name . " | " . $p->city . "\n";
    }
    exit;
}

foreach ($props as $p) {
    echo "Property ID: {$p->id}\n";
    echo "Name: {$p->name}\n";
    echo "City: {$p->city}\n\n";

    $rooms = Room::where('property_id', $p->id)->get();
    echo "Rooms found: " . $rooms->count() . "\n";
    foreach ($rooms as $r) {
        echo "\n  Room ID: {$r->id}\n";
        echo "  Name: {$r->name}\n";
        echo "  Type: {$r->type}\n";
        echo "  Price/night: {$r->price_per_night}\n";
        echo "  Available (field): " . ($r->available ? 'true' : 'false') . "\n";
        echo "  Max Guests: {$r->max_guests}\n";
        echo "  Total Rooms: {$r->total_rooms}\n";
        
        // Check bookings for these dates
        $checkIn  = '2026-08-16';
        $checkOut = '2026-08-17';
        $bookings = Booking::where('room_id', $r->id)
            ->where('status', '!=', 'cancelled')
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn)
            ->count();
        echo "  Conflicting bookings for {$checkIn} - {$checkOut}: {$bookings}\n";
    }
}
