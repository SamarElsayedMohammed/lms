<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ContactMessage;
use App\Traits\PushesToFirebase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\ConfigurableNotification;

/**
 * Sent to the registered user when the admin replies to their contact message.
 * Channels: database (in-app) + Firebase FCM push.
 */
class ContactReplyNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    /** Email is sent separately in ContactMessageAdminApiController::reply(). */
    protected array $defaultChannels = ['database'];

    public function __construct(
        private readonly ContactMessage $contactMessage,
        private readonly string $replyMessage,
        private readonly string $appName,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type'               => 'contact_reply',
            'title'              => "رد على رسالتك - {$this->appName}",
            'message'            => $this->replyMessage,
            'contact_message_id' => $this->contactMessage->id,
            'original_message'   => mb_substr($this->contactMessage->message, 0, 100),
            'action_url'         => null,
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => "رد على رسالتك - {$this->appName}",
            'body'  => $this->replyMessage,
            'type'  => 'contact_reply',
        ]);

        return new DatabaseMessage($data);
    }
}
