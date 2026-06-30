<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use App\Models\Webinar;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class WebinarRegistrationNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    private $webinar;
    private $isReminder;
    protected array $defaultChannels = ['database', 'mail'];

    /**
     * Create a new notification instance.
     */
    public function __construct(Webinar $webinar, bool $isReminder = false)
    {
        $this->webinar = $webinar;
        $this->isReminder = $isReminder;
    }



    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->isReminder ? 'تذكير: ويبينار قادم | Webinar Reminder' : 'تأكيد التسجيل: ' . $this->webinar->title;
        $title = $this->isReminder ? 'موعد الويبينار اقترب!' : 'تم تأكيد تسجيلك بنجاح';
        $message = $this->isReminder 
            ? "نود تذكيرك بأن الويبينار <strong>{$this->webinar->title}</strong> سيبدأ قريباً." 
            : "لقد قمت بالتسجيل بنجاح في الويبينار: <strong>{$this->webinar->title}</strong>.";
            
        $message .= "<br><br>وقت البدء: " . $this->webinar->start_at->format('Y-m-d H:i A');

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.general-notification', [
                'notificationTitle' => $title,
                'greeting' => "مرحباً {$notifiable->name}،",
                'notificationContent' => $message,
                'actionUrl' => url('/webinars/' . $this->webinar->slug),
                'actionText' => 'عرض تفاصيل الويبينار',
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
            'type' => $this->isReminder ? 'webinar_reminder' : 'webinar_registration',
            'webinar_id' => $this->webinar->id,
            'title' => $this->isReminder ? 'Webinar Reminder' : 'Webinar Registration Confirmed',
            'message' => $this->isReminder ? 'Starting soon: ' . $this->webinar->title : 'You are successfully registered for: ' . $this->webinar->title,
            'start_at' => $this->webinar->start_at->toIso8601String(),
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => $data['title'],
            'body' => $data['message'],
            'type' => $data['type'],
        ]);
        
        return new DatabaseMessage($data);
    }
}
