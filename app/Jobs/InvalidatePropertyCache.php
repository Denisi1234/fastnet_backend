<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Queued Job: Invalidate Redis property cache when data changes.
 *
 * Triggered when:
 *  - A property is created, updated, or status-changed
 *  - A room is added, updated, or deleted
 *  - A booking is confirmed (availability changes)
 */
class InvalidatePropertyCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly ?int $propertyId = null,  // null = bust ALL caches
        public readonly ?string $city = null
    ) {}

    public function handle(): void
    {
        try {
            // 1. Invalidate the specific property detail cache
            if ($this->propertyId) {
                Cache::forget("property:{$this->propertyId}");
                Cache::forget("property:detail:{$this->propertyId}");
            }

            // 2. Invalidate the city-level listing cache
            if ($this->city) {
                $cityKey = 'properties:city:' . strtolower($this->city);
                Cache::forget($cityKey);
            }

            // 3. Always bust the hot deals / featured properties cache
            Cache::forget('properties:hot_deals');
            Cache::forget('properties:all');

            // 4. Bust popular destination caches for major cities
            $popularCities = ['dar es salaam', 'zanzibar', 'arusha', 'dodoma', 'mwanza', 'mbeya'];
            foreach ($popularCities as $city) {
                Cache::forget("properties:city:{$city}");
                Cache::forget("search:{$city}");
            }

            Log::info("Property cache invalidation complete for property #{$this->propertyId}.");
        } catch (\Exception $e) {
            Log::error("InvalidatePropertyCache failed: " . $e->getMessage());
        }
    }
}
