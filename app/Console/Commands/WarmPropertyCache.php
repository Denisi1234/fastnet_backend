<?php

namespace App\Console\Commands;

use App\Models\Property;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * php artisan cache:warm
 *
 * Pre-loads hot Redis cache with high-value property data so the first
 * user search after a deploy or cache flush is still fast.
 *
 * Schedule this in console.php to run every 30 minutes:
 *   Schedule::command('cache:warm')->everyThirtyMinutes();
 */
class WarmPropertyCache extends Command
{
    protected $signature   = 'cache:warm {--force : Bust existing cache before warming}';
    protected $description = 'Pre-warm Redis with hot property data (popular cities, featured lodges)';

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

    public function handle(): int
    {
        $this->info('🔥 FastNetStays Cache Warmer starting...');
        $start = microtime(true);

        if ($this->option('force')) {
            $this->warn('  Busting existing caches...');
            $this->bustAll();
        }

        // 1. Warm the "all properties" cache (used by homepage hot deals)
        $this->warmAllProperties();

        // 2. Warm city-level caches (most common search type)
        foreach ($this->hotCities as $city) {
            $this->warmCity($city);
        }

        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $this->info("✅ Cache warming complete in {$elapsed}ms.");

        return Command::SUCCESS;
    }

    private function warmAllProperties(): void
    {
        $this->line('  Warming: properties:all ...');

        $properties = Property::with(['rooms', 'host'])
            ->where('status', 'Active')
            ->orderByDesc('created_at')
            ->limit(100) // Only top 100 — don't load unlimited into RAM
            ->get();

        Cache::put('properties:all', $properties, 1800); // 30 min TTL
        $this->info("    ✓ Cached {$properties->count()} properties (all).");
    }

    private function warmCity(string $city): void
    {
        $this->line("  Warming: properties:city:{$city} ...");

        $properties = Property::with(['rooms', 'host'])
            ->where('status', 'Active')
            ->where('city', 'like', '%' . $city . '%')
            ->orderByDesc('created_at')
            ->limit(50) // Cap at 50 per city
            ->get();

        $key = 'properties:city:' . $city;
        Cache::put($key, $properties, 1800); // 30 min TTL

        // Also warm the search key for this city
        Cache::put("search:{$city}", $properties, 600); // 10 min TTL

        $this->info("    ✓ Cached {$properties->count()} properties for '{$city}'.");
    }

    private function bustAll(): void
    {
        Cache::forget('properties:all');
        foreach ($this->hotCities as $city) {
            Cache::forget("properties:city:{$city}");
            Cache::forget("search:{$city}");
        }
    }
}
