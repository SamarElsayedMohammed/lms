<?php

namespace App\Notifications;

use App\Traits\PushesToFirebase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Services\NotificationSettingsService;

class ManualCustomNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use PushesToFirebase;

    public int $tries = 3;
    public int $timeout = 60;
    public int|array $backoff = [10, 30];

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
        $title = $this->data['title_ar'] ?? $this->data['title'] ?? 'إشعار';
        $body = $this->data['message_ar'] ?? $this->data['message'] ?? '';

        return (new MailMessage)
            ->subject($title)
            ->view('emails.general-notification', [
                'notificationTitle'   => $title,
                'greeting'            => 'مرحباً ' . ($notifiable->name ?? '') . '،',
                'notificationContent' => $body,
                'actionUrl'           => isset($this->data['action_url']) && $this->data['action_url'] !== '#'
                                            ? url($this->data['action_url'])
                                            : null,
                'actionText'          => 'عرض التفاصيل',
                'imageUrl'            => $this->data['image'] ?? null,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $titleAr = $this->data['title_ar'] ?? $this->data['title'] ?? 'إشعار';
        $messageAr = $this->data['message_ar'] ?? $this->data['message'] ?? '';

        return [
            'title'      => $titleAr,
            'title_ar'   => $titleAr,
            'message'    => $messageAr,
            'message_ar' => $messageAr,
            'action_url' => $this->data['action_url'] ?? '#',
            'image'      => $this->data['image'] ?? null,
            'icon'       => $this->data['icon'] ?? null,
            'icon_color' => $this->data['icon_color'] ?? null,
            'type'       => $this->data['type'] ?? 'admin_manual',
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => $data['title_ar'],
            'body'  => $data['message_ar'],
            'type'  => $data['type'],
            'link'  => $data['action_url'],
        ]);

        return new DatabaseMessage($data);
    }
}
