<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    protected $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('مرحباً بك في منصة Skillso')
            ->view('emails.welcome', [
                'userName' => $this->user->name,
                'actionUrl' => url('/courses'),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'أهلاً بك في سكيلسو!',
            'title_ar' => 'أهلاً بك في سكيلسو!',
            'message' => 'أهلاً بك في منصتنا! ابدأ رحلتك التعليمية اليوم.',
            'message_ar' => 'أهلاً بك في منصتنا! ابدأ رحلتك التعليمية اليوم.',
            'action_url' => '/courses',
            'type' => 'welcome'
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
