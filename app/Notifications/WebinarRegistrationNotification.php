<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Webinar;

class WebinarRegistrationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private $webinar;
    private $isReminder;

    /**
     * Create a new notification instance.
     */
    public function __construct(Webinar $webinar, bool $isReminder = false)
    {
        $this->webinar = $webinar;
        $this->isReminder = $isReminder;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database']; // Add 'mail' if email is needed
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->isReminder ? 'Reminder: Upcoming Webinar' : 'Registration Confirmed: ' . $this->webinar->title;
        $greeting = $this->isReminder ? 'Your webinar is starting soon!' : 'You have successfully registered for the webinar: ' . $this->webinar->title;

        return (new MailMessage)
            ->subject($subject)
            ->line($greeting)
            ->line('Start Time: ' . $this->webinar->start_at->format('Y-m-d H:i A'))
            ->action('View Dashboard', url('/'))
            ->line('Thank you for using our platform!');
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
}
