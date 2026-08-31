<?php

namespace App\Services;

use App\Events\DocumentStatusChanged;
use App\Jobs\AutoApproveAssignmentJob;
use App\Models\AuditLog;
use App\Models\DocumentAssignment;
use App\Models\DocumentReviewSession;
use App\Models\NotificationRecord;
use App\Models\SlaViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SlaService
 * -----------
 * The second half of the Section 5 safety net (the first half — flagging
 * an individual expired assignment as escalated_to_admin — is handled by
 * the `workflow:check-parallel-slas` command). A stage can have more than
 * one seat (see WorkflowService — every eligible approver is assigned at
 * once), but every method here still operates strictly per-assignment-row
 * — each seat escalates/auto-approves/gets overridden completely
 * independently of any sibling seats on the same stage, which is exactly
 * the "each approver keeps their own independent SLA window" behavior the
 * multi-approver model requires. No cross-row coordination happens here;
 * WorkflowService::completeStage() is what decides whether an entire
 * stage is actually done once a given row resolves.
 *
 *   escalated_to_admin = true (set by workflow:check-parallel-slas)
 *     -> Admin may override: approve/reject on the approver's behalf
 *     -> if Admin is ALSO unresponsive for a grace window
 *        -> System Auto-Approval, with a high-priority notification sent
 *           to both Admin and Approver roles.
 *
 * Intended to run every few minutes via the scheduler alongside
 * workflow:check-parallel-slas (see bootstrap/app.php and README.md).
 */
class SlaService
{
    /**
     * Reuses the exact threshold the grace-period countdown already turns
     * red at (see admin/partials/sla-queue-results.blade.php's
     * data-live-urgent-under="7200") — the extra urgent notification fires
     * exactly when an admin would already see this flagged urgent on the
     * page, not a separately-invented number.
     */
    private const SHORT_GRACE_URGENT_SECONDS = 7200; // 2 hours

    public function __construct(private WorkflowService $workflow)
    {
    }

    /**
     * Reuses the same "Urgent" threshold (30 minutes or less of real
     * remaining time — see DocumentAssignment::URGENT_THRESHOLD_SECONDS)
     * already used everywhere else in this app, rather than a separately
     * invented number, for when the unreviewed-auto-approval reminder
     * escalates.
     */
    private const REVIEW_REMINDER_DUE_SOON_SECONDS = 1800; // 30 minutes

    /**
     * Same 30-minute "Urgent" threshold as above, reused (not a separately
     * invented number) as the final-call point for the two one-shot urgent
     * notifications below — each fires exactly once when its situation
     * first becomes urgent (see WorkflowService::assignStage() and
     * escalate()'s SHORT_GRACE_URGENT_SECONDS check), with no follow-up if
     * that first ping goes unseen. This is the ONE additional nudge, right
     * before the thing actually happens (SLA breach/escalation, or
     * auto-approval), guarded by its own *_reminder_sent_at column so it
     * can never repeat.
     */
    private const URGENT_FOLLOWUP_SECONDS = 1800; // 30 minutes

    public function sweep(): array
    {
        return [
            'auto_approved' => $this->autoApproveUnresolved(),
            'review_reminders_sent' => $this->remindUnreviewedAutoApprovals(),
            'urgent_approver_reminders_sent' => $this->remindStillUrgentApprovers(),
            'grace_reminders_sent' => $this->remindShortGraceWindows(),
        ];
    }

    /**
     * The "born urgent" notification (WorkflowService::assignStage()) fires
     * exactly once, the instant an assignment is created with an already-
     * short window. If the approver isn't looking right then, nothing
     * nudges them again before it actually escalates to Admin — this sends
     * ONE follow-up, right as the same Urgent window is about to run out,
     * guarded by urgent_reminder_sent_at.
     */
    private function remindStillUrgentApprovers(): int
    {
        $count = 0;

        DocumentAssignment::query()
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', false)
            ->whereNull('urgent_reminder_sent_at')
            ->whereNotNull('sla_expires_at')
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count) {
                if ($assignment->urgencyRank() !== 1) {
                    return;
                }

                $assignment->urgent_reminder_sent_at = now();
                $assignment->save();

                NotificationRecord::send($assignment->user_id, $assignment->document_id,
                    "FINAL CALL: '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') is about to breach its SLA window " .
                    '— please act now before it escalates to Admin.',
                    'high');

                $count++;
            });

        return $count;
    }

    /**
     * The "grace window is already short" notification (escalate()) fires
     * exactly once, at the moment of escalation itself. If it isn't seen,
     * nothing nudges Admins again as auto-approval gets closer — this
     * sends ONE follow-up right before the system actually auto-approves,
     * guarded by grace_reminder_sent_at. Reuses adminGraceExpiresAt() (the
     * same single source of truth escalate() and autoApproveUnresolved()
     * both use), so this can never disagree with when auto-approval will
     * actually happen.
     */
    private function remindShortGraceWindows(): int
    {
        $count = 0;

        DocumentAssignment::query()
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->whereNull('grace_reminder_sent_at')
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count) {
                $graceExpiresAt = $assignment->adminGraceExpiresAt();
                if (!$graceExpiresAt || now()->diffInSeconds($graceExpiresAt, false) > self::URGENT_FOLLOWUP_SECONDS) {
                    return;
                }

                $assignment->grace_reminder_sent_at = now();
                $assignment->save();

                foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
                    NotificationRecord::send($admin->user_id, $assignment->document_id,
                        "FINAL CALL: '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') will be auto-approved by the system " .
                        'very soon unless an Admin acts now.',
                        'high');
                }

                $count++;
            });

        return $count;
    }

    /**
     * Closes the gap between "the system auto-approved this" and "an admin
     * actually looked at it" — autoApproveOne() already sends an immediate
     * in-app notification the moment auto-approval happens, but nothing
     * previously followed up if that sat unreviewed. If a stage is still
     * auto_approved, still unreviewed, and its document's due date is now
     * within the same "Urgent" window used everywhere else in this app,
     * every admin gets ONE escalated reminder (review_reminder_sent_at
     * guards against re-sending it every sweep cycle for as long as it
     * stays unreviewed).
     */
    private function remindUnreviewedAutoApprovals(): int
    {
        $count = 0;

        DocumentAssignment::query()
            ->where('auto_approved', true)
            ->whereNull('admin_reviewed_at')
            ->whereNull('review_reminder_sent_at')
            ->whereHas('document', fn ($q) => $q->where('due_date', '<=', now()->addSeconds(self::REVIEW_REMINDER_DUE_SOON_SECONDS)))
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count) {
                $assignment->review_reminder_sent_at = now();
                $assignment->save();

                foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
                    NotificationRecord::send($admin->user_id, $assignment->document_id,
                        "URGENT: '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') was auto-approved " .
                        "and still hasn't been reviewed — its due date is approaching. Please confirm or dispute it now.",
                        'high');
                }

                $count++;
            });

        return $count;
    }

    /**
     * Flags a single expired-but-not-yet-escalated assignment: sets
     * escalated_to_admin, logs the violation to sla_violations, and notifies
     * Admins (in-app + email). This is the same logic the scheduled
     * `workflow:check-parallel-slas` command runs in bulk — factored out
     * here so it can ALSO be triggered on-demand (see ApprovalController)
     * the moment someone touches a since-expired assignment, rather than
     * depending entirely on the next cron tick. Without this, an approver
     * could still approve/reject an assignment whose SLA had already
     * lapsed, simply because the periodic sweep hadn't run yet.
     *
     * Also the entry point for a needs_approver seat's OWN deadline lapsing
     * (see WorkflowService::markNeedsApprover()) — same escalation, grace
     * period, and eventual auto-approval as a normal approver miss, but
     * $assignment->needs_approver branches the wording/record below: there's
     * no approver to blame here (that's the whole reason it's stuck), so
     * this must never read as "the approver missed it" or count against
     * whoever used to hold the seat.
     */
    public function escalate(DocumentAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $escalatedAt = now();
            $assignment->escalated_to_admin = true;
            $assignment->escalated_at = $escalatedAt;
            $assignment->save();

            if ($assignment->needs_approver) {
                AuditLog::record(null, $assignment->document_id, 'sla_escalation',
                    "Stage '{$assignment->stage->stage_name}' on '{$assignment->document->title}' has had no eligible " .
                    'approver for too long and was flagged for Admin escalation.');
            } else {
                AuditLog::record(null, $assignment->document_id, 'sla_escalation',
                    "Approver assignment #{$assignment->assignment_id} (stage '{$assignment->stage->stage_name}') " .
                    'exceeded its SLA window and was flagged for Admin escalation.');
            }

            // Event-driven auto-approval (mirrors EscalateAssignmentJob): fires
            // exactly when the Admin grace window lapses, instead of waiting
            // for the next 5-minute sla:check poll. sla:check stays wired into
            // the scheduler as a backstop only (see bootstrap/app.php).
            //
            // Reuses DocumentAssignment::adminGraceExpiresAt() — the single
            // source of truth for the grace deadline (due_date clamp AND the
            // short-due-date halving rule both live there) — instead of
            // recomputing the formula here, so this can never drift from
            // what the displayed countdown or the backstop sweep compute.
            $graceExpiresAt = $assignment->adminGraceExpiresAt();
            AutoApproveAssignmentJob::dispatch($assignment->assignment_id, $graceExpiresAt)->delay($graceExpiresAt);

            // No SlaViolation for a needs_approver seat — that table feeds
            // the approver leaderboard/roster on the Violations page, and
            // whoever used to hold this seat didn't fail anything; they
            // were deactivated with nobody else eligible. Logging one here
            // would unfairly count against their record for something that
            // isn't theirs.
            if (!$assignment->needs_approver) {
                // abs()+round(): Carbon 3's diffInMinutes() returns a signed
                // float even with the default $absolute param, so the sign
                // and fractional part both need normalizing before this
                // hits an unsignedInteger column.
                SlaViolation::create([
                    'document_id' => $assignment->document_id,
                    'assignment_id' => $assignment->assignment_id,
                    'approver_id' => $assignment->user_id,
                    'violation_timestamp' => now(),
                    'duration_overdue' => (int) round(abs(now()->diffInMinutes($assignment->sla_expires_at))),
                    'stage_name' => $assignment->stage->stage_name,
                ]);
            }

            $admins = User::where('role', 'admin')->where('is_active', true)->get();
            foreach ($admins as $admin) {
                $message = $assignment->needs_approver
                    ? "'{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') still has no eligible " .
                        'approver and its own deadline has now passed — needs Admin attention.'
                    : "SLA violation: '{$assignment->document->title}' at stage '{$assignment->stage->stage_name}' " .
                        '(approver: ' . ($assignment->approver->full_name ?? 'unassigned') . ') needs Admin attention.';

                NotificationRecord::send($admin->user_id, $assignment->document_id, $message, 'high');
            }

            // A SEPARATE, extra-urgent notification — not a duplicate of
            // the one above — for the specific case where the grace window
            // just computed is already short (reusing the same 2-hour mark
            // the grace countdown itself turns red at, so this fires
            // exactly when an admin would already see it flagged urgent on
            // the page). Escalation alone doesn't tell an admin whether
            // they have 6 hours or 20 minutes to act; this does.
            if (now()->diffInSeconds($graceExpiresAt, false) <= self::SHORT_GRACE_URGENT_SECONDS) {
                foreach ($admins as $admin) {
                    NotificationRecord::send($admin->user_id, $assignment->document_id,
                        "URGENT: '{$assignment->document->title}' (stage '{$assignment->stage->stage_name}') has a very short " .
                        'grace window before the system auto-approves it — please review now.',
                        'high');
                }
            }

            // DocumentAssignment::booted() only broadcasts on an
            // individual_status change — escalating to Admin never touches
            // that column, so without this the Admin dashboard's SLA alert
            // widget would only learn about the violation via a bell
            // notification, never a live list refresh. Reuses the same
            // event/channel the dashboard already listens on.
            event(new DocumentStatusChanged($assignment->document));
        });
    }

    /**
     * Escalated + Admin grace window also elapsed -> system resolves the
     * stage automatically via WorkflowService::completeStage(), which
     * advances/finalizes the document exactly as a human approval would.
     */
    private function autoApproveUnresolved(): int
    {
        $count = 0;

        // No ->unique('document_id') — a stage can now have more than one
        // eligible-approver seat (see WorkflowService::assignStage()), so
        // more than one row on the SAME document (even the same stage) can
        // legitimately qualify for auto-approval in the same sweep; each
        // needs its own independent auto-approval, not just the first one
        // found per document.
        //
        // Fetches every still-escalated, still-pending, not-yet-overridden
        // row (there's normally very few of these at once) and checks each
        // one's real grace deadline in PHP via adminGraceExpiresAt() —
        // the same single source of truth escalate() uses to schedule the
        // actual auto-approval job. A flat SQL cutoff can't express the
        // due-date clamp or the short-due-date halving rule that method
        // applies, so this is precise rather than an approximation of it.
        DocumentAssignment::query()
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', true)
            ->whereNull('admin_override_at')
            ->with(['document', 'stage'])
            ->get()
            ->each(function (DocumentAssignment $assignment) use (&$count) {
                $graceExpiresAt = $assignment->adminGraceExpiresAt();
                if ($graceExpiresAt && now()->greaterThanOrEqualTo($graceExpiresAt)) {
                    $this->autoApproveOne($assignment);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Shared by the sla:check polling backstop and AutoApproveAssignmentJob
     * (event-driven, fires exactly when the grace window lapses) — same
     * outcome either way, just triggered differently.
     */
    public function autoApproveOne(DocumentAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $document = $assignment->document;

            AuditLog::record(null, $document->document_id, 'auto_approve',
                "System auto-approved stage '{$assignment->stage->stage_name}' after Admin grace window elapsed with no response.");

            NotificationRecord::send($document->originator_id, $document->document_id,
                "Your document '{$document->title}' had a stage auto-approved by the system after an unresolved SLA violation.", 'high');

            foreach (User::whereIn('role', ['admin', 'approver'])->where('is_active', true)->get() as $u) {
                NotificationRecord::send($u->user_id, $document->document_id,
                    "HIGH PRIORITY: '{$document->title}' had a stage auto-approved by the system without human sign-off. Please review.",
                    'high');
            }

            // individual_status and auto_approved must be set here —
            // completeStage() only finalizes the DOCUMENT's
            // global_status; it never touches the assignment's own
            // status (that's the caller's job, same as decide() and
            // adminOverride() already do). Without this, the
            // assignment stays 'pending' forever and would get
            // caught — and re-notified on — every subsequent sweep.
            $assignment->individual_status = 'approved';
            $assignment->auto_approved = true;
            $assignment->acted_at = now();
            $assignment->save();

            $this->workflow->completeStage($assignment, 'approved', true);
        });
    }

    /**
     * Admin manually overrides a stuck assignment (approve or reject).
     * Routed through WorkflowService::completeStage() so the document
     * advances/finalizes exactly as it would from a normal approver decision.
     */
    public function adminOverride(DocumentAssignment $assignment, User $admin, string $decision, ?string $comments = null): void
    {
        DB::transaction(function () use ($assignment, $admin, $decision, $comments) {
            $assignment->admin_override_at = now();
            $assignment->admin_override_by = $admin->user_id;
            $assignment->individual_status = $decision;
            $assignment->comments = $comments;
            $assignment->acted_at = now();
            $assignment->save();

            $document = $assignment->document;
            DocumentReviewSession::closeFor($document, $admin);
            AuditLog::record($admin->user_id, $document->document_id, 'admin_override',
                "Admin {$admin->full_name} overrode stage '{$assignment->stage->stage_name}' -> {$decision}." . ($comments ? " Notes: {$comments}" : ''));

            $this->workflow->completeStage($assignment, $decision);

            NotificationRecord::send($document->originator_id, $document->document_id,
                "An Admin override was applied to your document '{$document->title}' ({$decision})." .
                ($comments ? " Notes: \"{$comments}\"" : ''));
        });
    }
}