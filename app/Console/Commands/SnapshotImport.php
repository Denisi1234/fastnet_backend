<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore a snapshot produced by snapshot:export.
 *
 * Order of operations is deliberate:
 *  1. Refuse when the target already holds business rows, unless --force.
 *     A snapshot is a whole-database move, not a merge — merging would
 *     collide ids and double-book rooms.
 *  2. FK checks off (session_replication_role = replica), truncate the 20
 *     business tables, insert, restore sequences, FK checks on.
 *  3. Extract media over storage/app/public, then run any pending migrations
 *     in case the code is newer than the snapshot.
 *  4. Flush the query/cache stores so nothing stale survives.
 *
 * APP_KEY must match the source for encrypted values to read. Copy it along
 * with the snapshot, or sessions/encrypted fields break silently.
 *
 *   php artisan snapshot:import --from=/tmp/snap/snapshot-20260101-120000 --force
 */
class SnapshotImport extends Command
{
    protected $signature = 'snapshot:import
                            {--from= : Snapshot directory (required)}
                            {--force : Allow import into a database that already holds rows}
                            {--rewrite-url= : Rewrite stored absolute URLs from the snapshot origin to this base (e.g. https://api.fastnetstays.com)}';

    protected $description = 'Restore a snapshot directory into this database + storage';

    public function handle(): int
    {
        $dir = rtrim((string)$this->option('from'), '/');
        if ($dir === '' || ! is_dir($dir)) {
            $this->error('Point --from at a snapshot directory from snapshot:export.');

            return self::FAILURE;
        }

        $manifestPath = $dir . '/manifest.json';
        $manifest = $manifestPath && file_exists($manifestPath)
            ? json_decode((string)file_get_contents($manifestPath), true)
            : [];

        if (! $this->option('force')) {
            $existing = 0;
            foreach (SnapshotExport::BUSINESS_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $existing += DB::table($table)->count();
                }
            }
            if ($existing > 0) {
                $this->error("Target holds {$existing} business rows. Re-run with --force to replace them.");
                $this->line('Snapshots replace, never merge — merging would collide ids.');

                return self::FAILURE;
            }
        } else {
            $this->warn('FORCE: existing business rows will be replaced.');
        }

        DB::statement('SET session_replication_role = replica');
        try {
            foreach (SnapshotExport::BUSINESS_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::table($table)->truncate();
                $file = $dir . "/tables/{$table}.json";
                if (! file_exists($file)) {
                    continue;
                }
                $rows = json_decode((string)file_get_contents($file), true) ?? [];
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
                $this->line('  ' . str_pad($table, 24) . count($rows) . ' rows');
            }

            $seqFile = $dir . '/sequences.json';
            if (file_exists($seqFile)) {
                $seqs = json_decode((string)file_get_contents($seqFile), true) ?? [];
                foreach ($seqs as $name => $s) {
                    $safe = '"' . str_replace('"', '""', $name) . '"';
                    DB::statement("SELECT setval('{$safe}', ?, ?)", [
                        (int)$s['last_value'], (bool)$s['is_called'],
                    ]);
                }
            }
        } finally {
            DB::statement('SET session_replication_role = DEFAULT');
        }

        $this->restoreMedia($dir . '/media.tar.gz');

        $rewriteBase = trim((string)$this->option('rewrite-url'), '/');
        $originBase = is_array($manifest) ? trim((string)($manifest['app_url'] ?? ''), '/') : '';
        if ($rewriteBase !== '' && $originBase !== '' && $rewriteBase !== $originBase) {
            $this->rewriteUrls($originBase, $rewriteBase);
        }

        $this->call('migrate', ['--force' => true]);
        $this->call('cache:clear');

        $total = is_array($manifest) ? ($manifest['total_rows'] ?? '?') : '?';
        $this->info("Import complete ({$total} rows per manifest). Users must sign in again — sessions and tokens never travel.");

        return self::SUCCESS;
    }

private function restoreMedia(string $archive): void
    {
        if (! file_exists($archive)) {
            $this->line('  no media archive — skipping');

            return;
        }

        $public = storage_path('app/public');
        @mkdir($public, 0775, true);
        $cmd = 'tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($public) . ' 2>/dev/null';
        exec($cmd, $ignored, $code);

        if ($code !== 0) {
            $this->error('Media extraction failed. Copy the files manually.');
            $this->line('  archive: ' . $archive . '  target: ' . $public);

            return;
        }

        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && basename($file->getPathname()) !== '.gitignore') {
                $count++;
            }
        }
        $this->line("  media files restored: {$count}");
    }

    /**
     * Rewrite stored absolute URLs from the snapshot origin to the new base.
     *
     * Uploads are stored as APP_URL/storage/... strings. Without rewriting, a
     * snapshot taken on localhost restores image URLs that 404 in production.
     * Only values starting with the exact origin base are touched; relative
     * paths and third-party URLs pass through unchanged.
     */
    private function rewriteUrls(string $originBase, string $newBase): void
    {
        $touched = 0;
        foreach (SnapshotExport::BUSINESS_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                $id = $row->id ?? null;
                if ($id === null) {
                    continue;
                }
                $updates = [];
                foreach ($columns as $col) {
                    $val = $row->{$col} ?? null;
                    if (! is_string($val) || $val === '') {
                        continue;
                    }
                    $new = $this->rewriteValue($val, $originBase, $newBase);
                    if ($new !== $val) {
                        $updates[$col] = $new;
                    }
                }
                if ($updates !== []) {
                    DB::table($table)->where('id', $id)->update($updates);
                    $touched++;
                }
            }
        }
        $this->line("  URLs rewritten {$originBase} -> {$newBase} on {$touched} rows");
    }

    private function rewriteValue(string $val, string $originBase, string $newBase): string
    {
        // JSON-encoded arrays (room photos) may embed URLs inside strings.
        $decoded = json_decode($val, true);
        if (is_array($decoded)) {
            $changed = false;
            array_walk_recursive($decoded, function (&$v) use ($originBase, $newBase, &$changed) {
                if (is_string($v) && str_starts_with($v, $originBase)) {
                    $v = $newBase . substr($v, strlen($originBase));
                    $changed = true;
                }
            });

            return $changed ? (string)json_encode($decoded, JSON_UNESCAPED_SLASHES) : $val;
        }

        if (str_starts_with($val, $originBase)) {
            return $newBase . substr($val, strlen($originBase));
        }

        return $val;
    }
}