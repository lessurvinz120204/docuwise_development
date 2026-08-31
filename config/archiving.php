<?php

return [
    // How many days a table's rows stay live before being exported to a
    // compressed archive file and removed from the database — see
    // App\Console\Commands\ArchiveOldRecords.
    //
    // audit_logs and document_review_sessions are both deliberately NOT
    // listed here — DocumentMovementTimeline::build() (the Document
    // Tracker) reads both live from the database with no archive
    // fallback, so pruning either would silently shorten old documents'
    // tracker history. See ArchiveOldRecords's class docblock for the
    // full reasoning. notification_records has no such dependency.
    'notification_records_days' => env('ARCHIVE_NOTIFICATION_RECORDS_DAYS', 90),
];
