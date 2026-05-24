<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use App\Helpers\FirebaseHelper;
use App\Models\UserFcmToken;
use Illuminate\Support\Facades\Log;

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

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $this->sendFcmNotification($notifiable);
        return new DatabaseMessage($this->toArray($notifiable));
    }

    private function sendFcmNotification($notifiable)
    {
        try {
            $fcmData = [
                'title' => $this->data['title'],
                'body' => $this->data['message'],
                'type' => $this->data['type'] ?? 'admin_manual',
                'link' => $this->data['action_url'] ?? '#',
            ];

            $tokens = UserFcmToken::where('user_id', $notifiable->id)
                ->select('fcm_token', 'platform_type')
                ->get();

            if ($tokens->isEmpty()) {
                return;
            }

            foreach ($tokens as $token) {
                try {
                    $platform = match (strtolower((string) $token->platform_type)) {
                        'ios' => 'ios',
                        'android' => 'android',
                        default => 'web',
                    };
                    
                    FirebaseHelper::send($platform, $token->fcm_token, $fcmData, [
                        'title' => $this->data['title'],
                        'body' => $this->data['message'],
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send FCM bulk notification', [
                        'user_id' => $notifiable->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to process FCM bulk notifications', [
                'user_id' => $notifiable->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
