<?php

namespace App\Models;

use App\Events\DocumentStatusChanged;
use Illuminate\Database\Eloquent\Model;

class DocumentRepository extends Model
{
    protected $table = 'document_repository';
    protected $primaryKey = 'document_id';

    /**
     * Broadcasts DocumentStatusChanged over Reverb whenever global_status
     * OR disputed_at actually changes, from wherever it changes — a single
     * hook here instead of manually firing the event at every call site
     * that touches either column, so a future new call site can't silently
     * forget to push the update live. disputed_at is included alongside
     * global_status because AdminController::reviewAutoApproval()'s
     * dispute path deliberately never touches global_status (there's no
     * "reopen" path to reverse an auto-approval — see that method's
     * docblock), so without this the originator would only see a dispute
     * via the ~5-10s polling fallback instead of instantly like every
     * other status change.
     */
    protected static function booted(): void
    {
        static::updated(function (self $document) {
            if ($document->wasChanged('global_status') || $document->wasChanged('disputed_at')) {
                event(new DocumentStatusChanged($document));
            }
        });
    }

    protected $fillable = [
        'originator_id', 'batch_id', 'model_id', 'title', 'file_path', 'original_filename',
        'mime_type', 'ocr_text', 'used_ocr_fallback', 'ml_category', 'ml_confidence',
        'is_validated', 'validation_errors', 'due_date', 'global_status',
        'previous_version_id', 'version_number', 'is_legacy_import', 'disputed_at',
        'ml_review_status', 'ml_recheck_category', 'ml_recheck_confidence', 'ml_rechecked_at',
        'ml_recheck_dismissed_at', 'confirmed_at_model_id', 'requires_printing',
        'readability_score', 'readability_review_status', 'is_security_blocked',
    ];

    protected $casts = [
        'validation_errors' => 'array',
        'is_validated' => 'boolean',
        'used_ocr_fallback' => 'boolean',
        'is_legacy_import' => 'boolean',
        'due_date' => 'datetime',
        'upload_date' => 'datetime',
        'disputed_at' => 'datetime',
        'ml_recheck_confidence' => 'float',
        'ml_rechecked_at' => 'datetime',
        'ml_recheck_dismissed_at' => 'datetime',
        'requires_printing' => 'boolean',
        'is_security_blocked' => 'boolean',
    ];

    // Every state a document can be in — mirrors Section 5 state machine.
    public const STATES = ['processing', 'classified_validated', 'approved', 'auto_approved', 'rejected'];

    /**
     * What <x-status-badge> should show — 'disputed'/'pending_review'
     * override the underlying global_status without replacing it (an
     * admin-held document is still, technically, 'classified_validated';
     * this just tells the UI not to say "Awaiting Approval" for one that
     * was never actually routed to an approver — see WorkflowService::
     * process() and AdminController::reviewFlaggedDocument()).
     */
    public function getDisplayStatusAttribute(): string
    {
        if ($this->is_security_blocked) {
            return 'security_blocked';
        }
        if ($this->disputed_at) {
            return 'disputed';
        }
        if ($this->ml_review_status === 'pending' && $this->global_status === 'classified_validated') {
            return 'pending_review';
        }
        if ($this->readability_review_status === 'pending') {
            return 'pending_review';
        }
        return $this->global_status;
    }

    /**
     * Urgent=1, Normal=2, Low=3, Expired=4 — driven by real (business-
     * hours-aware) remaining time before this document's own due_date,
     * using the exact same 30-minute/2-hour thresholds
     * DocumentAssignment::urgencyRank() already uses for an approver's own
     * SLA countdown elsewhere in this app. Meant for documents that
     * haven't reached an approver yet (an Admin review queue item), where
     * there's no assignment/sla_expires_at to check yet — this is the same
     * "how much real runway is left" signal WorkflowService::
     * extendDueDateIfReviewQueueAteTheBuffer() reacts to, surfaced here so
     * an Admin sees a queue item running low on runway BEFORE that safety
     * net has to kick in.
     */
    public function dueDateUrgencyRank(): ?int
    {
        if (!$this->due_date) {
            return null;
        }

        $remaining = app(\App\Services\BusinessHoursService::class)->businessSecondsRemaining(now(), $this->due_date);

        return match(true) {
            $remaining <= 0 => 4,
            $remaining <= 1800 => 1,
            $remaining <= 7200 => 2,
            default => 3,
        };
    }

    public function dueDateUrgencyLabel(): ?string
    {
        $rank = $this->dueDateUrgencyRank();

        return $rank ? [1 => 'Urgent', 2 => 'Normal', 3 => 'Low', 4 => 'Expired'][$rank] : null;
    }

    public function originator()
    {
        return $this->belongsTo(User::class, 'originator_id', 'user_id');
    }

    /**
     * The submission this document was uploaded alongside (nullable — older
     * documents predate batching, and are treated as a single-document
     * container of their own in the grouped dashboards).
     */
    public function batch()
    {
        return $this->belongsTo(SubmissionBatch::class, 'batch_id', 'batch_id');
    }

    public function model()
    {
        return $this->belongsTo(MlModelRepository::class, 'model_id', 'model_id');
    }

    public function confirmedAtModel()
    {
        return $this->belongsTo(MlModelRepository::class, 'confirmed_at_model_id', 'model_id');
    }

    public function assignments()
    {
        return $this->hasMany(DocumentAssignment::class, 'document_id', 'document_id')
            ->orderBy('stage_id');
    }

    public function currentAssignment()
    {
        return $this->hasOne(DocumentAssignment::class, 'document_id', 'document_id')
            ->where('individual_status', 'pending')
            ->orderBy('stage_id');
    }

    public function auditLogs()
    {
        // Secondary tiebreak on log_id (auto-increment, so it reflects true
        // insertion order) matters a lot here: `timestamp` is a
        // second-precision column, and a single WorkflowService::ingest()
        // call fires several AuditLog rows (upload, validate, classify,
        // route per stage) synchronously within the same wall-clock
        // second — without this, MySQL has no defined order for those
        // ties and the movement timeline renders them scrambled.
        return $this->hasMany(AuditLog::class, 'document_id', 'document_id')
            ->orderByDesc('timestamp')
            ->orderByDesc('log_id');
    }

    /** The document this one was resubmitted to revise, if any. */
    public function previousVersion()
    {
        return $this->belongsTo(self::class, 'previous_version_id', 'document_id');
    }

    /** The resubmission that superseded this document, if one exists yet. */
    public function nextVersion()
    {
        return $this->hasOne(self::class, 'previous_version_id', 'document_id');
    }

    public function scopeForOriginator($query, $userId)
    {
        return $query->where('originator_id', $userId);
    }
}