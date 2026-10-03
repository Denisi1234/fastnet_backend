<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Export a portable snapshot of everything the localhost build created.
 *
 * Produces <dir>/snapshot-<timestamp>/ containing:
 *  - tables/*.json     one JSON array per business table
 *  - sequences.json    next-id counters so new rows keep incrementing
 *  - media.tar.gz      storage/app/public (room photos, covers, avatars)
 *  - manifest.json     counts, app version, migration batch, source URL
 *
 * Only business tables travel. Transient state stays behind on purpose:
 * cache, sessions, queues, login OTPs, reset tokens, access tokens and room
 * locks are single-machine or single-login state — restoring them would log
 * strangers in or replay one-time codes. Users simply sign in again.
 *
 *   php artisan snapshot:export --to=/tmp/snap
 */
class SnapshotExport extends Command
{
    protected $signature = 'snapshot:export
                            {--to= : Destination directory (default storage/app/snapshots)}
                            {--prune= : Keep only the N newest snapshots, delete the rest}';

    protected $description = 'Export business data + media into a portable snapshot directory';

    /** Tables that carry the build. Everything else is machine state. */
    public const BUSINESS_TABLES = [
        'users',
        'properties',
        'rooms',
        'bookings',
        'payments',
        'payouts',
        'reviews',
        'messages',
        'tickets',
        'notifications',
        'staff',
        'lodge_documents',
        'lodge_service_requests',
        'newsletter_subscriptions',
        'owner_verifications',
        'verification_requests',
        'wishlist_lists',
        'wishlists',
        'saved_payment_methods',
        'accessibility_feedbacks',
    ];

    public function handle(): int
    {
        $base = rtrim((string)($this->option('to') ?: storage_path('app/snapshots')), '/');
        $dir = $base . '/snapshot-' . date('Ymd-His');
        @mkdir($dir . '/tables', 0775, true);

        $counts = [];
        foreach (self::BUSINESS_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->orderBy('id')->get()
                ->map(fn($r) => $this->plainRow((array)$r))
                ->all();
            file_put_contents($dir . "/tables/{$table}.json", json_encode($rows, JSON_UNESCAPED_SLASHES));
            $counts[$table] = count($rows);
        }

        file_put_contents($dir . '/sequences.json', json_encode($this->sequences(), JSON_PRETTY_PRINT));

        $mediaCount = $this->archiveMedia($dir . '/media.tar.gz');

        $manifest = [
            'exported_at'     => now()->toIso8601String(),
            'app_url'         => config('app.url'),
            'migration_batch' => DB::table('migrations')->max('batch'),
            'tables'          => $counts,
            'total_rows'      => array_sum($counts),
            'media_files'     => $mediaCount,
        ];
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $this->info("Snapshot written to {$dir}");
        $this->line('  rows: ' . $manifest['total_rows'] . '  media files: ' . $mediaCount);

        $prune = (int)$this->option('prune');
        if ($prune > 0) {
            $this->pruneOld($base, $prune);
        }

        return self::SUCCESS;
    }

    private function plainRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if ($v instanceof \DateTimeInterface) {
                $row[$k] = $v->format('Y-m-d H:i:s');
            }
        }

        return $row;
    }

    /** Next-id counters for every serial PK, so inserts continue after import. */
    private function sequences(): array
    {
        $out = [];
        $seqs = DB::select(
            "SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public'"
        );
        foreach ($seqs as $s) {
            $name = is_array($s) ? $s['sequence_name'] : $s->sequence_name;
            $row = DB::selectOne("SELECT last_value, is_called FROM \"{$name}\"");
            $val = is_array($row) ? $row : (array)$row;
            $out[$name] = [
                'last_value' => (int)$val['last_value'],
                'is_called'  => (bool)$val['is_called'],
            ];
        }

        return $out;
    }

private function archiveMedia(string $target): int
    {
        $public = storage_path('app/public');
        $files = [];
        if (is_dir($public)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile() && basename($file->getPathname()) !== '.gitignore') {
                    $files[] = $file->getPathname();
                }
            }
        }

        if ($files === []) {
            return 0;
        }

        // System tar preserves permissions and is far faster than PharData.
        $list = implode(' ', array_map('escapeshellarg', array_map(
            fn($f) => ltrim(substr($f, strlen($public)), '/'),
            $files
        )));
        $cmd = 'tar -czf ' . escapeshellarg($target) . ' -C ' . escapeshellarg($public) . ' ' . $list . ' 2>/dev/null';
        exec($cmd, $ignored, $code);

        if ($code !== 0 || ! file_exists($target)) {
            $this->warn('tar unavailable — media skipped. Install tar or copy storage/app/public manually.');
            @unlink($target);

            return 0;
        }

        return count($files);
    }

    /**
     * Delete all but the N newest snapshot-* directories. Old restore points
     * pile up fast (each carries a full media archive), so daily automation
     * must not grow the disk without bound.
     */
    private function pruneOld(string $base, int $keep): void
    {
        $dirs = glob($base . '/snapshot-*', GLOB_ONLYDIR) ?: [];
        usort($dirs, fn($a, $b) => strcmp($b, $a));
        foreach (array_slice($dirs, $keep) as $old) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($old, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($old);
            $this->line('  pruned ' . basename($old));
        }
    }
}