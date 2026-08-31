<?php

use App\Models\DocumentReviewSession;
use App\Models\NotificationRecord;
use App\Models\User;

/**
 * Covers App\Console\Commands\ArchiveOldRecords — old notification_records
 * rows get exported to a compressed, readably-named file before being
 * removed, recent rows are left alone, an empty table is a no-op rather
 * than an error, and audit_logs/document_review_sessions are left
 * completely untouched regardless of age (both feed the Document
 * Tracker's live-only query — see the command's own class docblock).
 */
function archiveTestDir(string $slug): string
{
    return storage_path("app/archives/{$slug}");
}

afterEach(function () {
    foreach (['document-review-sessions', 'notification-records'] as $slug) {
        $dir = archiveTestDir($slug);
        if (is_dir($dir)) {
            foreach (glob("{$dir}/*") as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
});

it('leaves document_review_sessions completely untouched, regardless of age', function () {
    // Same reasoning as audit_logs below: DocumentMovementTimeline::
    // build() (the Document Tracker) reads this table live with no
    // archive fallback — pruning it would silently shorten old
    // documents' "Review Activity" history. See ArchiveOldRecords's
    // class docblock.
    $user = User::factory()->originator()->create();
    $document = \App\Models\DocumentRepository::create([
        'originator_id' => $user->user_id,
        'title' => 'archive-test.txt',
        'file_path' => 'documents/archive-test.txt',
        'mime_type' => 'text/plain',
        'due_date' => now()->addDay(),
        'global_status' => 'classified_validated',
        'ml_category' => 'Job Order',
    ]);

    $veryOld = DocumentReviewSession::create([
        'document_id' => $document->document_id,
        'user_id' => $user->user_id,
        'opened_at' => now()->subYears(3),
        'closed_at' => now()->subYears(3)->addMinutes(5),
        'session_type' => 'initial',
        'duration_seconds' => 300,
    ]);

    $this->artisan('records:archive')->assertSuccessful();

    expect(DocumentReviewSession::find($veryOld->id))->not->toBeNull();
    expect(is_dir(archiveTestDir('document-review-sessions')))->toBeFalse();
});

it('archives old notification records and deletes them, leaving recent ones alone', function () {
    config(['archiving.notification_records_days' => 90]);

    $user = User::factory()->originator()->create();

    $old = NotificationRecord::create([
        'recipient_id' => $user->user_id,
        'document_id' => null,
        'message_body' => 'An old notification',
        'priority' => 'normal',
        'is_read' => true,
        'created_at' => now()->subDays(120),
    ]);

    $recent = NotificationRecord::create([
        'recipient_id' => $user->user_id,
        'document_id' => null,
        'message_body' => 'A recent notification',
        'priority' => 'normal',
        'is_read' => false,
        'created_at' => now()->subDays(10),
    ]);

    $this->artisan('records:archive')->assertSuccessful();

    expect(NotificationRecord::find($old->notification_id))->toBeNull();
    expect(NotificationRecord::find($recent->notification_id))->not->toBeNull();

    $dir = archiveTestDir('notification-records');
    $files = glob("{$dir}/*.json.gz");
    expect($files)->toHaveCount(1);
});

it('does nothing and does not create a file when nothing is old enough to archive', function () {
    $user = User::factory()->originator()->create();

    NotificationRecord::create([
        'recipient_id' => $user->user_id,
        'document_id' => null,
        'message_body' => 'Fresh notification',
        'priority' => 'normal',
        'is_read' => false,
        'created_at' => now(),
    ]);

    $this->artisan('records:archive')->assertSuccessful();

    expect(NotificationRecord::count())->toBe(1);
    expect(is_dir(archiveTestDir('notification-records')))->toBeFalse();
});

it('audit_logs is left completely untouched by the archive command', function () {
    \App\Models\AuditLog::record(null, null, 'login', 'A very old login.', '127.0.0.1');
    \App\Models\AuditLog::query()->update(['timestamp' => now()->subYears(5)]);

    $this->artisan('records:archive')->assertSuccessful();

    // Still there — this command never touches audit_logs at all (see its
    // own docblock: AuditLog's immutability is deliberate, not handled
    // here).
    expect(\App\Models\AuditLog::count())->toBe(1);
});
