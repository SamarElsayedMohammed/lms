<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ContactMessage;
use App\Traits\PushesToFirebase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\ConfigurableNotification;

/**
 * Sent to all admin users when a new contact-us message arrives.
 * Channels: database (in-app) + Firebase FCM push.
 */
class AdminNewContactMessageNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    /** Email is now sent via notification. */
    protected array $defaultChannels = ['database', 'mail'];

    public function __construct(
        private readonly ContactMessage $contactMessage,
        private readonly string $appName,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('رسالة تواصل جديدة | New Contact Message')
            ->view('emails.admin-notification', [
                'notificationTitle' => 'رسالة تواصل جديدة',
                'notificationContent' => "قام <strong>{$this->contactMessage->first_name}</strong> ({$this->contactMessage->email}) بإرسال رسالة جديدة:<br><br><em>{$this->contactMessage->message}</em>",
                'senderName' => $this->contactMessage->first_name,
                'actionUrl' => url('/admin/contact-messages'),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $senderName = $this->contactMessage->first_name;

        return [
            'type'               => 'admin_new_contact_message',
            'title'              => 'رسالة تواصل جديدة',
            'message'            => "المستخدم {$senderName} أرسل رسالة جديدة تحتاج للمراجعة.",
            'contact_message_id' => $this->contactMessage->id,
            'sender_name'        => $senderName,
            'sender_email'       => $this->contactMessage->email,
            'preview'            => mb_substr($this->contactMessage->message, 0, 100),
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => 'رسالة تواصل جديدة',
            'body'  => "من: {$data['sender_name']} — {$data['preview']}",
            'type'  => 'admin_new_contact_message',
        ]);

        return new DatabaseMessage($data);
    }
}
