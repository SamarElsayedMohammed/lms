<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class CertificateNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('إصدار شهادة جديدة')
            ->view('emails.general-notification', [
                'notificationTitle' => 'إصدار شهادة جديدة',
                'greeting' => "مرحباً {$notifiable->name}،",
                'notificationContent' => "تهانينا! لقد تم إصدار شهادة جديدة لك لإتمامك أحد الكورسات بنجاح. يمكنك الآن استعراضها وتحميلها من لوحة التحكم الخاصة بك.",
                'actionUrl' => url('/my-certificates'),
                'actionText' => 'عرض الشهادات',
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'certificate_issued',
            'title' => 'تم إصدار شهادة جديدة',
            'title_ar' => 'تم إصدار شهادة جديدة',
            'message' => 'تهانينا! حصلت على شهادة جديدة بعد إتمام الدورة.',
            'message_ar' => 'تهانينا! حصلت على شهادة جديدة بعد إتمام الدورة.',
            'action_url' => '/my-certificates',
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => $data['title_ar'] ?? $data['title'],
            'body' => $data['message_ar'] ?? $data['message'],
            'type' => $data['type'],
        ]);
        
        return new DatabaseMessage($data);
    }
}
