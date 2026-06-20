<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

/**
 * Notifies the subscriber when their subscription is successfully renewed (wallet / immediate payment).
 */
class SubscriptionRenewedNotification extends Notification
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    public function __construct(
        protected Subscription $subscription,
        protected float $amountPaid = 0.0
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $planName = $this->subscription->plan->name ?? 'غير محدد';
        $endsAt   = $this->subscription->ends_at?->format('Y-m-d') ?? 'مدى الحياة';

        return (new MailMessage)
            ->subject("تم تجديد اشتراكك في باقة: {$planName}")
            ->greeting("مرحباً {$notifiable->name}!")
            ->line("تم تجديد اشتراكك في باقة **{$planName}** بنجاح.")
            ->line("تاريخ انتهاء الاشتراك الجديد: **{$endsAt}**")
            ->action('عرض اشتراكي', url('/subscription/my-subscription'))
            ->line('شكراً لاستمرارك معنا!');
    }

    public function toArray(object $notifiable): array
    {
        $planName = $this->subscription->plan->name ?? 'غير محدد';
        $endsAt   = $this->subscription->ends_at?->format('Y-m-d H:i:s');

        return [
            'type'            => 'subscription',
            'title'           => 'تم تجديد اشتراكك بنجاح',
            'message'         => "تم تجديد اشتراكك في باقة {$planName} بنجاح.",
            'subscription_id' => $this->subscription->id,
            'plan_name'       => $planName,
            'ends_at'         => $endsAt,
            'amount_paid'     => $this->amountPaid,
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data     = $this->toArray($notifiable);
        $planName = $data['plan_name'];

        $this->sendFcmNotification($notifiable, [
            'title' => 'تجديد الاشتراك',
            'body'  => "تم تجديد اشتراكك في باقة {$planName} بنجاح.",
            'type'  => 'subscription',
        ]);

        return new DatabaseMessage($data);
    }
}
