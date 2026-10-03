<?php

namespace App\Console\Commands;

use App\Services\PropertySearchService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * php artisan cache:warm
 *
 * Pre-loads the search cache so the first visitor after a deploy or a cache
 * flush still gets a fast response.
 *
 * Warm-up runs through PropertySearchService on purpose: writing the cache
 * keys by hand here used to produce keys the search path never reads, holding
 * Eloquent collections where the search path expects a serialised API payload.
 * Driving the real code path guarantees the key and the payload shape match.
 *
 * Schedule in routes/console.php:
 *   Schedule::command('cache:warm')->everyThirtyMinutes();
 */
class WarmPropertyCache extends Command
{
    protected $signature   = 'cache:warm {--force : Bust existing cache before warming}';
    protected $description = 'Pre-warm the property search cache for high-traffic queries';

    /** Cities to pre-cache. Add more as the platform grows. */
    private array $hotCities = [
        'dar es salaam',
        'zanzibar',
        'arusha',
        'dodoma',
        'mwanza',
        'mbeya',
        'tanga',
    ];

    public function handle(PropertySearchService $search): int
    {
        $this->info('FastNetStays cache warmer starting...');
        $start = microtime(true);

        if ($this->option('force')) {
            $this->warn('  Bumping search generation...');
            PropertySearchService::bumpSearchVersion();
        }

        // 1. The bare listing (homepage hot deals).
        $this->warm($search, 'all properties', []);

        // 2. City-scoped listings - the most common search shape.
        foreach ($this->hotCities as $city) {
            $this->warm($search, "city: {$city}", ['city' => $city]);
            $this->warm($search, "search: {$city}", ['q' => $city]);
        }

        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $this->info("Cache warming complete in {$elapsed}ms.");

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function warm(PropertySearchService $search, string $label, array $params): void
    {
        $request = Request::create('/api/properties', 'GET', $params);

        $result = $search->search($request);

        $this->line(sprintf(
            '  %-22s %s (%d results)',
            $label,
            $result['cache_status'] === 'HIT' ? 'already warm' : 'warmed',
            count($result['data']['data'] ?? [])
        ));
    }
}