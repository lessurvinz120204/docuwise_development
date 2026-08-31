<?php

namespace App\Policies;

use App\Models\DocumentAssignment;
use App\Models\DocumentRepository;
use App\Models\User;

/**
 * Centralizes the ownership checks that were previously hand-rolled per
 * controller method (DocumentController::show()/trackingRefresh()/
 * trackingPoll()/viewFile()/presence()/presenceLeave()/resubmit(), each
 * re-deriving the same "is this the owner, an admin, or an assigned
 * approver" logic independently) — one place to get right instead of N
 * places to remember to keep in sync.
 *
 * Two distinct view abilities, not one: the originator-facing tracking page
 * (viewTracking) never showed an assigned approver their own progress view
 * — that's a different page, the Approver queue — while the actual document
 * FILE (viewFile) legitimately needs to be visible to whichever approver is
 * reviewing it, in addition to the owner and any admin. Collapsing these
 * into one ability would either wrongly deny an approver the file or
 * wrongly let an approver onto the originator's tracking page.
 */
class DocumentRepositoryPolicy
{
    public function viewTracking(User $user, DocumentRepository $document): bool
    {
        return $document->originator_id === $user->user_id || $user->isAdmin();
    }

    public function viewFile(User $user, DocumentRepository $document): bool
    {
        // Unconditional, even for an Admin — the whole point of blocking
        // it (see WorkflowService::blockForSecurity()) is that nothing in
        // this app ever hands the flagged file back out, since opening it
        // locally afterward is exactly the risk being avoided. The file
        // still physically exists in storage (inert — never opened or
        // executed by anything the app itself does), just never served.
        if ($document->is_security_blocked) {
            return false;
        }

        return $document->originator_id === $user->user_id
            || $user->isAdmin()
            || $this->isAssignedApprover($user, $document);
    }

    /**
     * Ownership only — whether the document's current state actually
     * permits a resubmission (global_status === 'rejected') is a state
     * guard, not an authorization question, and stays in the controller as
     * a 409 Conflict rather than a 403 Forbidden.
     */
    public function resubmit(User $user, DocumentRepository $document): bool
    {
        return $document->originator_id === $user->user_id;
    }

    private function isAssignedApprover(User $user, DocumentRepository $document): bool
    {
        if (!$user->isApprover()) {
            return false;
        }

        return DocumentAssignment::where('document_id', $document->document_id)
            ->where('user_id', $user->user_id)
            ->exists();
    }
}
