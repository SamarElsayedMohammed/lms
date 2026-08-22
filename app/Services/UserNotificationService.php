<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotificationRead;

final class UserNotificationService
{
    /**
     * Count every unread notification visible in the canonical user feed:
     * personal Laravel notifications plus global notifications created after
     * the user joined and not acknowledged by that user.
     */
    public function unreadCount(User $user): int
    {
        $personalUnread = $user->unreadNotifications()->count();
        $registrationDate = $user->created_at ?? now();

        $readGlobalIds = UserNotificationRead::query()
            ->where('user_id', $user->id)
            ->select('notification_id');

        $globalUnread = Notification::query()
            ->where('date_sent', '>=', $registrationDate)
            ->whereNotIn('id', $readGlobalIds)
            ->count();

        return $personalUnread + $globalUnread;
    }
}
