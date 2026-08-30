<?php

namespace App\Policies;

use App\Models\NotificationRecord;
use App\Models\User;

class NotificationRecordPolicy
{
    public function markRead(User $user, NotificationRecord $notification): bool
    {
        return $notification->recipient_id === $user->user_id;
    }
}
