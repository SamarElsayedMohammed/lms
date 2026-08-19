<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class SubscriptionExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    protected $subscription;
    protected $daysRemaining;

    /**
     * Create a new notification instance.
     */
    public function __construct(Subscription $subscription, int $daysRemaining)
    {
        $this->subscription = $subscription;
        $this->daysRemaining = $daysRemaining;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $planName = $this->subscription->plan->name;
        
        $message = (new MailMessage);
        
        if ($this->daysRemaining <= 0) {
            $message->subject("انتهى اشتراكك في باقة: {$planName}")
                    ->view('emails.subscription-expired', [
                        'userName' => $notifiable->name,
                        'planName' => $planName,
                        'renewUrl' => url('/subscription/plans'),
                    ]);
        } else {
            $message->subject("تذكير: اقتراب انتهاء اشتراكك في باقة: {$planName}")
                    ->view('emails.subscription-expiring', [
                        'userName' => $notifiable->name,
                        'planName' => $planName,
                        'daysRemaining' => $this->daysRemaining,
                        'renewUrl' => url('/subscription/plans'),
                    ]);
        }

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $days = $this->daysRemaining;
        $planName = $this->subscription->plan->name ?? 'اشتراكك';
        $title = $days <= 0
            ? 'انتهى اشتراكك'
            : 'اشتراكك أوشك على الانتهاء';
        $message = $days <= 0
            ? "انتهت صلاحية باقة {$planName}."
            : ($days === 1
                ? "تنتهي باقة {$planName} خلال 24 ساعة. جدد الآن لتحتفظ بوصولك."
                : "تنتهي باقة {$planName} خلال {$days} أيام. جدد الآن لتحتفظ بوصولك.");

        return [
            'subscription_id' => $this->subscription->id,
            'plan_name' => $planName,
            'days_remaining' => $days,
            'expiry_date' => $this->subscription->ends_at?->format('Y-m-d'),
            'title' => $title,
            'title_ar' => $title,
            'message' => $message,
            'message_ar' => $message,
            'action_url' => '/subscription/plans',
            'type' => 'subscription_expiry',
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
