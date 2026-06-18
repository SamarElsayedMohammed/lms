<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Rating;
use App\Models\Course\CourseDiscussion;
use App\Traits\PushesToFirebase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the user when their review/comment is approved or rejected.
 * Channels: database (in-app) + Firebase FCM push.
 */
class ReviewStatusNotification extends Notification
{
    use Queueable, PushesToFirebase;

    public function __construct(
        private readonly Rating|CourseDiscussion $reviewItem,
        private readonly string $type = 'rating', // 'rating' or 'comment'
        private readonly string $status = 'approved', // 'approved' or 'rejected'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $itemTypeAr = $this->type === 'rating' ? 'تقييمك' : 'تعليقك';
        $statusAr = $this->status === 'approved' ? 'الموافقة على' : 'رفض';
        $messagePreview = $this->type === 'rating' ? ($this->reviewItem->review ?? 'تقييم بدون نص') : $this->reviewItem->message;

        return [
            'type'               => 'review_status_update',
            'title'              => "تحديث حالة {$itemTypeAr}",
            'message'            => "تم {$statusAr} {$itemTypeAr} الذي قمت بإضافته.",
            'item_id'            => $this->reviewItem->id,
            'item_type'          => $this->type,
            'status'             => $this->status,
            'preview'            => mb_substr((string)$messagePreview, 0, 100),
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => $data['title'],
            'body'  => $data['message'],
            'type'  => 'review_status_update',
        ]);

        return new DatabaseMessage($data);
    }
}
