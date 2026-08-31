<?php

namespace App\Console\Commands;

use App\Models\NotificationRecord;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Run via: php artisan records:archive
 * Scheduled nightly in bootstrap/app.php, right alongside backup:run.
 *
 * Exports rows older than each table's configured retention window
 * (config('archiving.php')) to a compressed, readably-named file under
 * storage/app/archives/ — same local-disk convention BackupSystem
 * already uses for backups — then deletes only the rows that were
 * actually archived. Nothing is silently gone: worst case, the data is
 * sitting in a file instead of a live database row.
 *
 * audit_logs and document_review_sessions are both deliberately NOT
 * handled here, for the same reason: DocumentMovementTimeline::build()
 * — what renders the Document Tracker on every document — reads BOTH
 * tables live from the database with no archive fallback. Pruning either
 * one would silently shorten old documents' tracker history (audit
 * events from one table, "Review Activity" rows from the other) with no
 * visible sign anything was ever removed. AuditLog's immutability is
 * also a separate, independently documented guarantee (see its booted()
 * docblock) — but even without that, the tracker-completeness reasoning
 * alone rules out pruning either table. notification_records has no such
 * dependency — nothing renders a permanent "notification history" from
 * it anywhere — so it's the only one still safe to prune here.
 */
class ArchiveOldRecords extends Command
{
    protected $signature = 'records:archive';
    protected $description = 'Archives (exports, then deletes) rows older than each table\'s configured retention window.';

    public function handle(): int
    {
        $ok = $this->archive(
            NotificationRecord::query(),
            'notification-records',
            'created_at',
            (int) config('archiving.notification_records_days', 90),
        );

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function archive(Builder $query, string $slug, string $dateColumn, int $retentionDays): bool
    {
        $cutoff = now()->subDays($retentionDays);
        $scoped = (clone $query)->where($dateColumn, '<', $cutoff);

        $count = $scoped->count();
        if ($count === 0) {
            $this->info("{$slug}: nothing older than {$retentionDays} days — nothing to archive.");

            return true;
        }

        $oldest = (clone $scoped)->min($dateColumn);
        $newest = (clone $scoped)->max($dateColumn);
        $startLabel = Carbon::parse($oldest)->format('Y-m-d');
        $endLabel = Carbon::parse($newest)->format('Y-m-d');

        $dir = storage_path("app/archives/{$slug}");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Descriptive, not a hash or a timestamp alone — which table and
        // which date range it covers is readable straight off the
        // filename, no need to open the file to know what's in it.
        $filename = "{$slug}_{$startLabel}_to_{$endLabel}.json.gz";
        $path = "{$dir}/{$filename}";

        // Chunked, not one giant get()->toJson() — this table can hold a
        // lot of history by the time archiving actually runs on it, and
        // this avoids loading it all into memory at once.
        $rows = [];
        (clone $scoped)->orderBy($dateColumn)->chunk(500, function ($chunk) use (&$rows) {
            foreach ($chunk as $row) {
                $rows[] = $row->toArray();
            }
        });

        $written = @file_put_contents($path, gzencode(json_encode($rows, JSON_PRETTY_PRINT), 9));
        if ($written === false) {
            Log::error("Archiving failed: could not write {$path}");
            $this->error("{$slug}: failed to write archive file — nothing was deleted.");

            return false;
        }

        (clone $scoped)->delete();

        $this->info("{$slug}: archived {$count} row(s) ({$startLabel} to {$endLabel}) to {$path}, removed from the database.");
        Log::info("Archived {$count} {$slug} row(s) to {$path}");

        return true;
    }
}
