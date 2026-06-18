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
 * Sent to all admin users when a new rating or comment arrives.
 * Channels: database (in-app) + Firebase FCM push.
 */
class AdminNewReviewNotification extends Notification
{
    use Queueable, PushesToFirebase;

    public function __construct(
        private readonly Rating|CourseDiscussion $reviewItem,
        private readonly string $type = 'rating', // 'rating' or 'comment'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $senderName = $this->reviewItem->user ? $this->reviewItem->user->name : 'مستخدم';
        
        $itemTypeAr = $this->type === 'rating' ? 'تقييم' : 'تعليق';
        $messagePreview = $this->type === 'rating' ? ($this->reviewItem->review ?? 'تقييم بدون نص') : $this->reviewItem->message;

        return [
            'type'               => 'admin_new_review',
            'title'              => "{$itemTypeAr} جديد بانتظار الموافقة",
            'message'            => "أضاف {$senderName} {$itemTypeAr}اً جديداً يحتاج لمراجعتك.",
            'item_id'            => $this->reviewItem->id,
            'item_type'          => $this->type,
            'sender_name'        => $senderName,
            'preview'            => mb_substr((string)$messagePreview, 0, 100),
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => $data['title'],
            'body'  => "من: {$data['sender_name']} — {$data['preview']}",
            'type'  => 'admin_new_review',
        ]);

        return new DatabaseMessage($data);
    }
}
