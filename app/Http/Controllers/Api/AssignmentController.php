<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentResource;
use App\Models\DocumentAssignment;
use App\Services\SlaService;
use App\Services\WorkflowService;
use Illuminate\Http\Request;

/**
 * JSON equivalent of the approver dashboard/decide flow
 * (App\Http\Controllers\ApprovalController) — same ownership checks, same
 * on-demand SLA escalation guard, same WorkflowService::decide() call.
 * Deliberately a flat list rather than the web dashboard's nested
 * batch/document/stage container grouping — that grouping exists for
 * human-readable Blade rendering; an API client is better served by a
 * plain array it can sort/group itself.
 */
class AssignmentController extends Controller
{
    public function __construct(private WorkflowService $workflow, private SlaService $sla)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->isApprover(), 403, 'Only approver accounts have assignments.');

        $userId = $request->user()->user_id;

        DocumentAssignment::where('user_id', $userId)
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', false)
            ->where('sla_expires_at', '<', now())
            ->with(['stage', 'document', 'approver'])
            ->get()
            ->each(fn (DocumentAssignment $a) => $this->sla->escalate($a));

        $assignments = DocumentAssignment::where('user_id', $userId)
            ->where('individual_status', 'pending')
            ->where('escalated_to_admin', false)
            ->with(['document', 'stage'])
            ->orderBy('priority_rank')
            ->orderBy('sla_expires_at')
            ->paginate(20);

        return AssignmentResource::collection($assignments);
    }

    public function decide(Request $request, DocumentAssignment $assignment)
    {
        $this->authorize('decide', $assignment);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if($assignment->individual_status !== 'pending', 409, 'This assignment has already been actioned.');

        if (!$assignment->escalated_to_admin && $assignment->sla_expires_at && now()->greaterThan($assignment->sla_expires_at)) {
            $this->sla->escalate($assignment);
        }
        abort_if($assignment->escalated_to_admin, 409, "This assignment's SLA deadline has passed — it was just escalated to Admin and can no longer be decided here.");

        $this->workflow->decide($assignment, $request->user(), $validated['decision'], $validated['comments'] ?? null);

        return new AssignmentResource($assignment->fresh(['document', 'stage']));
    }
}
