<?php

namespace App\Jobs;

use App\Services\PropertySearchService;
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
                Cache::forget("property:detail:v2:{$this->propertyId}");
            }

            // 2. Bump the search generation. Listing keys are versioned
            //    (search:{x}:v{n}, properties:city:{x}:v{n}), so forgetting a
            //    single literal key would leave every version in place. One
            //    bump retires them all at once.
            $version = PropertySearchService::bumpSearchVersion();

            Log::info("Property cache invalidation complete for property #{$this->propertyId} (search v{$version}).");
        } catch (\Exception $e) {
            Log::error("InvalidatePropertyCache failed: " . $e->getMessage());
        }
    }
}
