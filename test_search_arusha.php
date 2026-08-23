<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\PropertySearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

$searchService = app(PropertySearchService::class);

$testCases = [
    ['q' => 'Arusha'],
    ['q' => 'arusha'],
    ['q' => 'ARUSHA'],
    ['q' => 'Aru'],
    ['q' => 'Arusha, Tanzania'],
    ['destination' => 'Arusha'],
    ['dest' => 'Arusha'],
    ['q' => 'SUNRISE'],
    ['q' => 'sekei'],
    ['q' => '45,sekei'],
    ['q' => 'NonExistentLodgeCity12345'], // Should return 0
];

foreach ($testCases as $idx => $params) {
    Cache::flush();
    $req = Request::create('/api/properties', 'GET', $params);
    $result = $searchService->search($req);
    $count = count($result['data'] ?? []);
    echo "Test #$idx (" . json_encode($params) . ") => Found $count stays\n";
    if ($count > 0) {
        foreach ($result['data'] as $p) {
            echo "   -> [ID: {$p['id']}] {$p['name']} in {$p['city']}, {$p['area']}\n";
        }
    }
}
