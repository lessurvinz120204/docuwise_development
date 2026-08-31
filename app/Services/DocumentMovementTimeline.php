<?php

namespace App\Services;

use App\Models\DocumentRepository;
use App\Models\DocumentReviewSession;
use Illuminate\Support\Collection;

/**
 * Merges a document's audit log entries with its review-session open/close
 * events into one chronological, friendly-labeled feed. Shared by every
 * view that needs to show "everything that happened to this document" —
 * the document-movement-timeline component (list format, Admin Document
 * Tracking module + Audit Logs nested view) and the Originator's Audit
 * Trail table (table format) both build from this same data rather than
 * duplicating the action-label map and merge logic in two places.
 */
class DocumentMovementTimeline
{
    public const ACTION_LABELS = [
        'login' => 'Signed In', 'logout' => 'Signed Out',
        'api_login' => 'Signed In (API)', 'api_logout' => 'Signed Out (API)',
        'email_verified' => 'Email Verified', 'password_reset' => 'Password Reset',
        'upload' => 'Uploaded', 'classify' => 'Classified', 'validate' => 'Validated',
        'route' => 'Routed', 'route_no_approver' => 'Routing Failed (No Approver)',
        'stage_complete' => 'Stage Completed',
        'approve' => 'Approved', 'reject' => 'Rejected', 'approved' => 'Approved', 'rejected' => 'Rejected',
        'admin_override' => 'Admin Override', 'admin_review' => 'Admin Review', 'admin_dispute' => 'Admin Dispute',
        'auto_approve' => 'Auto-Approved', 'sla_escalation' => 'SLA Escalation',
        'assignment_reassigned' => 'Reassigned', 'assignment_withdrawn' => 'Seat Withdrawn', 'needs_approver' => 'Needs Approver', 'due_date_adjusted' => 'Due Date Adjusted',
        'sla_recalculated' => 'SLA Recalculated',
        'finalize' => 'Finalized', 'resubmit' => 'Resubmitted',
        'archive_download' => 'Downloaded', 'legacy_import' => 'Legacy Import',
        'ml_recheck' => 'ML Recheck', 'ml_review_confirm' => 'ML Review Confirmed', 'ml_review_reject' => 'ML Review Rejected',
        'ml_train' => 'Model Retrained',
        'user_create' => 'Account Created', 'user_toggle' => 'Account Toggled', 'assign_stages' => 'Stages Assigned',
        'workflow_config' => 'Workflow Config Changed', 'sla_settings_update' => 'SLA Settings Updated',
        'sla_holiday_add' => 'Holiday Added', 'sla_holiday_remove' => 'Holiday Removed', 'extraction_failed' => 'Extraction Failed',
        'security_blocked' => 'Blocked — Security Scan',
        'view_backup_codes' => 'Viewed Backup Codes', 'view_backup_codes_denied' => 'Backup Codes Access Denied',
    ];

    /**
     * Which of five visually distinct buckets an action_type belongs to —
     * shared by the Audit Logs table (system-level rows) and this
     * timeline (both audit-log-derived events and review-session events)
     * so the same action is always the same color everywhere it shows up,
     * not just wherever it happened to be styled first.
     */
    public const ACTION_CATEGORIES = [
        'login' => 'session', 'logout' => 'session', 'api_login' => 'session', 'api_logout' => 'session',
        'upload' => 'lifecycle', 'extraction_failed' => 'lifecycle', 'classify' => 'lifecycle',
        'validate' => 'lifecycle', 'route' => 'lifecycle', 'route_no_approver' => 'lifecycle',
        'finalize' => 'lifecycle', 'legacy_import' => 'lifecycle', 'archive_download' => 'lifecycle',
        'resubmit' => 'lifecycle', 'stage_complete' => 'lifecycle',
        'approve' => 'approved', 'approved' => 'approved',
        'reject' => 'rejected', 'rejected' => 'rejected',
        'admin_override' => 'escalation', 'auto_approve' => 'escalation', 'sla_escalation' => 'escalation', 'needs_approver' => 'escalation',
        'security_blocked' => 'rejected',
    ];

    public const CATEGORY_CLASSES = [
        'session' => 'bg-surface-100 text-surface-500',
        'lifecycle' => 'bg-primary-50 text-primary-700',
        'approved' => 'bg-approved-50 text-approved-700',
        'rejected' => 'bg-rejected-50 text-rejected-700',
        'escalation' => 'bg-processing-50 text-processing-700',
        'review' => 'bg-primary-50 text-primary-700', // Review Started/Ended — not a real action_type, so its own bucket
        'config' => 'bg-indigo-50 text-indigo-700', // default: workflow_config, sla_settings_update, sla_holiday_*, due_date_adjusted, sla_recalculated, user_create, user_toggle, assign_stages, ml_train
    ];

    private static function formatSeconds(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
    }

    /**
     * Returns events shaped as ['timestamp', 'label', 'actor', 'note', 'kind', 'category'],
     * sorted newest-first. 'kind' is 'audit' or 'session_group' (which
     * merge source it came from); 'category' is the color bucket from
     * CATEGORY_CLASSES above — look up CATEGORY_CLASSES[$event['category']]
     * for the CSS classes to render it with.
     *
     * A 'session_group' event additionally carries 'passes_detail' — a
     * pre-formatted string of every individual open/close pass for that
     * one person, meant to render inside a collapsed/expandable detail
     * rather than as its own row. Review activity is grouped ONE ROW PER
     * PERSON (every pass they made on this document, not one row per
     * open/close) rather than the flat "Review Started"/"Review Ended"
     * pair-per-pass this used to emit — a document with a few approvers
     * each reviewing more than once could otherwise drown the actually
     * meaningful audit events (routed, approved, rejected, ...) under a
     * long run of review-session noise. Positioned in the chronological
     * feed at that person's MOST RECENT activity on this document (open
     * or close, whichever is later), so it still sits roughly where their
     * latest involvement happened rather than at the very top or bottom.
     */
    public static function build(DocumentRepository $document): Collection
    {
        $auditEvents = $document->auditLogs->map(fn ($log) => [
            'timestamp' => $log->timestamp,
            'label' => self::ACTION_LABELS[$log->action_type] ?? ucfirst(str_replace('_', ' ', $log->action_type)),
            'actor' => $log->user->full_name ?? 'System',
            'note' => $log->description,
            'kind' => 'audit',
            'category' => self::ACTION_CATEGORIES[$log->action_type] ?? 'config',
        ]);

        $sessionGroupEvents = DocumentReviewSession::where('document_id', $document->document_id)
            ->with('user')->orderBy('opened_at')->get()
            ->groupBy('user_id')
            ->map(function ($sessions) {
                $actor = $sessions->first()->user->full_name ?? 'Unknown';
                $totalSeconds = (int) $sessions->sum('duration_seconds');
                $stillOpen = $sessions->contains(fn ($s) => is_null($s->closed_at));
                $passCount = $sessions->count();

                $passesDetail = $sessions->map(function ($s) {
                    $from = $s->opened_at->format('g:i A');
                    if (!$s->closed_at) {
                        return "{$from} – still open";
                    }
                    $to = $s->closed_at->isSameDay($s->opened_at)
                        ? $s->closed_at->format('g:i A')
                        : $s->closed_at->format('M j, g:i A');
                    return "{$from}–{$to} (" . self::formatSeconds($s->duration_seconds ?? 0) . ')';
                })->implode(', ');

                $note = $passCount . ' pass' . ($passCount === 1 ? '' : 'es')
                    . ' — ' . self::formatSeconds($totalSeconds) . ' total'
                    . ($stillOpen ? ', still reviewing' : '');

                return [
                    'timestamp' => $sessions->max(fn ($s) => $s->closed_at ?? $s->opened_at),
                    'label' => 'Review Activity',
                    'actor' => $actor,
                    'note' => $note,
                    'kind' => 'session_group',
                    'category' => 'review',
                    'passes_detail' => $passesDetail,
                ];
            })->values();

        return $auditEvents->concat($sessionGroupEvents)->sortByDesc('timestamp')->values();
    }
}
