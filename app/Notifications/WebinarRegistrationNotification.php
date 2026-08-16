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

    private Webinar $webinar;
    private bool $isReminder;
    private bool $isCancelled;
    protected array $defaultChannels = ['database', 'mail'];

    /**
     * Create a new notification instance.
     */
    public function __construct(Webinar $webinar, bool $isReminder = false, bool $isCancelled = false)
    {
        $this->webinar = $webinar;
        $this->isReminder = $isReminder;
        $this->isCancelled = $isCancelled;
    }

    /**
     * Format start time respecting user timezone if available.
     */
    protected function formatStartTime(object $notifiable): string
    {
        if (!$this->webinar->start_at) {
            return '';
        }

        $userTimezone = $notifiable->timezone ?? config('app.timezone', 'UTC');
        try {
            $formatted = $this->webinar->start_at->copy()->setTimezone($userTimezone)->format('Y-m-d h:i A');
            return "{$formatted} ({$userTimezone})";
        } catch (\Throwable $e) {
            return $this->webinar->start_at->format('Y-m-d H:i') . ' UTC';
        }
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $formattedTime = $this->formatStartTime($notifiable);

        if ($this->isCancelled) {
            $subject = 'إلغاء ويبينار | Webinar Cancelled: ' . $this->webinar->title;
            $title = 'تم إلغاء الويبينار';
            $message = "نحيطك علماً بأنه قد تم إلغاء الويبينار: <strong>{$this->webinar->title}</strong>.";
            if ($formattedTime) {
                $message .= "<br><br>الموعد الملغي كان: " . $formattedTime;
            }
            $actionText = 'تصفح الويبنارات المتاحة';
            $actionUrl = url('/webinars');
        } elseif ($this->isReminder) {
            $subject = 'تذكير: ويبينار قادم | Webinar Reminder: ' . $this->webinar->title;
            $title = 'موعد الويبينار اقترب!';
            $message = "نود تذكيرك بأن الويبينار <strong>{$this->webinar->title}</strong> سيبدأ قريباً.";
            if ($formattedTime) {
                $message .= "<br><br>وقت البدء: " . $formattedTime;
            }
            $actionText = 'عرض تفاصيل الويبينار';
            $actionUrl = url('/webinars/' . $this->webinar->slug);
        } else {
            $subject = 'تأكيد التسجيل: ' . $this->webinar->title;
            $title = 'تم تأكيد تسجيلك بنجاح';
            $message = "لقد قمت بالتسجيل بنجاح في الويبينار: <strong>{$this->webinar->title}</strong>.";
            if ($formattedTime) {
                $message .= "<br><br>وقت البدء: " . $formattedTime;
            }
            $actionText = 'عرض تفاصيل الويبينار';
            $actionUrl = url('/webinars/' . $this->webinar->slug);
        }

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.general-notification', [
                'notificationTitle' => $title,
                'greeting' => "مرحباً {$notifiable->name}،",
                'notificationContent' => $message,
                'actionUrl' => $actionUrl,
                'actionText' => $actionText,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        if ($this->isCancelled) {
            $type = 'webinar_cancelled';
            $title = 'Webinar Cancelled';
            $message = 'The webinar has been cancelled: ' . $this->webinar->title;
        } elseif ($this->isReminder) {
            $type = 'webinar_reminder';
            $title = 'Webinar Reminder';
            $message = 'Starting soon: ' . $this->webinar->title;
        } else {
            $type = 'webinar_registration';
            $title = 'Webinar Registration Confirmed';
            $message = 'You are successfully registered for: ' . $this->webinar->title;
        }

        return [
            'type' => $type,
            'webinar_id' => $this->webinar->id,
            'title' => $title,
            'message' => $message,
            'start_at' => $this->webinar->start_at ? $this->webinar->start_at->toIso8601String() : null,
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
