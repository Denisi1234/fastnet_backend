<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Room;
use Illuminate\Support\Facades\DB;

// Show all columns for room 1
echo "=== RAW DB ROW (rooms table, id=1) ===\n";
$row = DB::table('rooms')->where('property_id', 1)->first();
if ($row) {
    foreach ((array)$row as $col => $val) {
        echo "  $col: " . var_export($val, true) . "\n";
    }
} else {
    echo "  No rows found in rooms table for property_id=1!\n";
}

echo "\n=== ROOMS TABLE SCHEMA ===\n";
$cols = DB::select("SHOW COLUMNS FROM rooms");
foreach ($cols as $c) {
    echo "  {$c->Field} | {$c->Type} | NULL:{$c->Null} | Default:" . var_export($c->Default, true) . "\n";
}
