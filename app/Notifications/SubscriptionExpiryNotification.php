<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class SubscriptionExpiryNotification extends Notification
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
            $message->subject("انتهى اشتراكك في باقة: {$planName} | Subscription Expired")
                    ->view('emails.subscription-expired', [
                        'userName' => $notifiable->name,
                        'planName' => $planName,
                        'renewUrl' => url('/subscription/plans'),
                    ]);
        } else {
            $message->subject("تذكير: اقتراب انتهاء اشتراكك في باقة: {$planName} | Subscription Expiring Soon")
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
        return [
            'subscription_id' => $this->subscription->id,
            'plan_name' => $this->subscription->plan->name,
            'days_remaining' => $this->daysRemaining,
            'expiry_date' => $this->subscription->ends_at->format('Y-m-d'),
            'message' => "Your {$this->subscription->plan->name} subscription expires in {$this->daysRemaining} days.",
            'type' => 'subscription_expiry',
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => 'Subscription Expiring Soon',
            'body' => $data['message'],
            'type' => $data['type'],
        ]);
        
        return new DatabaseMessage($data);
    }
}
