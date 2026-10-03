<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $isProduction = $this->app->isProduction();

        // Turn lazy loading into a hard failure outside production. This is
        // what surfaces N+1 queries in development instead of discovering them
        // in production dashboards.
        Model::preventLazyLoading(! $isProduction);

        // Every public file URL the app stores — property covers, room photos,
        // profile pictures, receipts — is minted as APP_URL . '/storage/...'
        // (see config/filesystems.php 'public' disk). If APP_URL is still a
        // localhost placeholder in production, every upload silently stores a
        // URL no guest can ever load. Fail loud in the log at boot so a
        // misconfigured deploy is visible in the first minute, not the first
        // support ticket. The app keeps running; only URLs are at stake.
        if ($isProduction) {
            $appUrl = strtolower(trim((string) config('app.url')));
            $looksLocal = $appUrl === ''
                || str_contains($appUrl, 'localhost')
                || str_contains($appUrl, '127.0.0.1')
                || str_contains($appUrl, '::1')
                || str_ends_with($appUrl, '.local')
                || str_ends_with($appUrl, '.test');

            if ($looksLocal) {
                Log::critical(
                    'APP_URL is not a public host in production (' . config('app.url') . '). '
                    .'Uploads will store unreachable image URLs. Set APP_URL to the public API origin.'
                );
            }
        }

        $thresholdMs = (float) env('SLOW_QUERY_THRESHOLD_MS', 200);

        DB::listen(function ($query) use ($thresholdMs) {
            if ($query->time < $thresholdMs) {
                return;
            }

            Log::warning('Slow query detected', [
                'ms'    => round($query->time, 2),
                'sql'   => $query->sql,
                'bindings' => $query->bindings,
            ]);
        });
    }
}
