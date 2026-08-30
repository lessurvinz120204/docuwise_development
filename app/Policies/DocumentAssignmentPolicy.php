<?php

namespace App\Policies;

use App\Models\DocumentAssignment;
use App\Models\User;

class DocumentAssignmentPolicy
{
    /**
     * Whether $user holds this specific seat. Whether the seat is still
     * pending / not yet escalated / has met the minimum review time are all
     * state guards (409 Conflict / 422), not authorization, and stay in
     * ApprovalController::decide() as-is.
     */
    public function decide(User $user, DocumentAssignment $assignment): bool
    {
        return $assignment->user_id === $user->user_id;
    }
}
