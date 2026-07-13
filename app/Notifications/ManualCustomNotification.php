<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Helpers\FirebaseHelper;
use App\Models\UserFcmToken;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationSettingsService;

class ManualCustomNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected array $data;

    /**
     * Channels explicitly chosen by the admin when dispatching this campaign.
     * When set, they override the global notification_channels_config setting.
     * Allowed values: 'mail', 'database'
     * Note: 'web' in the UI maps to the 'database' channel internally.
     */
    protected ?array $forcedChannels;

    /**
     * @param array       $data           Notification payload
     * @param array|null  $forcedChannels e.g. ['mail','database'] or null to fall back to global config
     */
    public function __construct(array $data, ?array $forcedChannels = null)
    {
        $this->data          = $data;
        $this->forcedChannels = $forcedChannels;
    }

    /**
     * Resolve delivery channels.
     * Priority: per-send override → global notification_channels_config → default ['mail','database']
     */
    public function via(object $notifiable): array
    {
        if ($this->forcedChannels !== null) {
            return array_values(array_intersect($this->forcedChannels, ['mail', 'database']));
        }

        $default = ['mail', 'database'];
        return NotificationSettingsService::getChannelsFor(
            class_basename(self::class),
            $default
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->data['title'])
            ->view('emails.general-notification', [
                'notificationTitle'   => $this->data['title'],
                'greeting'            => "مرحباً {$notifiable->name}،",
                'notificationContent' => $this->data['message'],
                'actionUrl'           => isset($this->data['action_url']) && $this->data['action_url'] !== '#'
                                            ? url($this->data['action_url'])
                                            : null,
                'actionText'          => 'عرض التفاصيل',
                'imageUrl'            => $this->data['image'] ?? null,
            ]);

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'      => $this->data['title'],
            'title_ar'   => $this->data['title_ar'] ?? $this->data['title'],
            'message'    => $this->data['message'],
            'message_ar' => $this->data['message_ar'] ?? $this->data['message'],
            'action_url' => $this->data['action_url'] ?? '#',
            'image'      => $this->data['image'] ?? null,
            'icon'       => $this->data['icon'] ?? null,
            'icon_color' => $this->data['icon_color'] ?? null,
            'type'       => $this->data['type'] ?? 'admin_manual',
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $this->sendFcmNotification($notifiable);
        return new DatabaseMessage($this->toArray($notifiable));
    }

    private function sendFcmNotification($notifiable): void
    {
        try {
            $fcmData = [
                'title'      => $this->data['title'],
                'body'       => $this->data['message'],
                'type'       => $this->data['type'] ?? 'admin_manual',
                'link'       => $this->data['action_url'] ?? '#',
                'image'      => $this->data['image'] ?? null,
                'icon'       => $this->data['icon'] ?? null,
                'icon_color' => $this->data['icon_color'] ?? null,
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
                        'ios'     => 'ios',
                        'android' => 'android',
                        default   => 'web',
                    };

                    FirebaseHelper::send($platform, $token->fcm_token, $fcmData, [
                        'title' => $this->data['title'],
                        'body'  => $this->data['message'],
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send FCM bulk notification', [
                        'user_id' => $notifiable->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('ManualCustomNotification FCM error', [
                'user_id' => $notifiable->id ?? null,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
