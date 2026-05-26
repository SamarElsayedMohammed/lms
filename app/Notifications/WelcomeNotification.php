<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;

class WelcomeNotification extends Notification
{
    use Queueable, PushesToFirebase;

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
