<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManualCustomNotification extends Notification
{
    use Queueable;

    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject($this->data['title'])
                    ->line($this->data['message'])
                    ->action('View Details', url($this->data['action_url'] ?? '/'))
                    ->line('Thank you for being with us!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->data['title'],
            'title_ar' => $this->data['title_ar'] ?? $this->data['title'],
            'message' => $this->data['message'],
            'message_ar' => $this->data['message_ar'] ?? $this->data['message'],
            'action_url' => $this->data['action_url'] ?? '#',
            'type' => $this->data['type'] ?? 'admin_manual'
        ];
    }
}
