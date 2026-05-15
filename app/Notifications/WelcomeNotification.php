<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification
{
    use Queueable;

    protected $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject('Welcome to Skillso! | أهلاً بك في سكيلسو!')
                    ->greeting('Hello ' . $this->user->name . '!')
                    ->line('Welcome to our learning platform. We are excited to have you on board!')
                    ->line('أهلاً بك في منصتنا التعليمية. نحن متحمسون جداً لانضمامك إلينا!')
                    ->action('Start Learning | ابدأ التعلم الآن', url('/courses'))
                    ->line('Thank you for joining us!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Welcome to Skillso!',
            'title_ar' => 'أهلاً بك في سكيلسو!',
            'message' => 'Welcome to our platform! Start your learning journey today.',
            'message_ar' => 'أهلاً بك في منصتنا! ابدأ رحلتك التعليمية اليوم.',
            'action_url' => '/courses',
            'type' => 'welcome'
        ];
    }
}
