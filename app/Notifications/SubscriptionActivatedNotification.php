<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Traits\ConfigurableNotification;
use App\Traits\PushesToFirebase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionActivatedNotification extends Notification
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    public function __construct(
        protected Subscription $subscription
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $planName = $this->subscription->plan->name ?? 'غير محدد';
        $startsAt = $this->subscription->starts_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s');
        $endsAt = $this->subscription->ends_at?->format('Y-m-d H:i:s') ?? 'مدى الحياة';

        return (new MailMessage)
            ->subject("تم الاشتراك في باقة جديدة: {$planName}")
            ->greeting("مرحباً {$notifiable->name}!")
            ->line("تم تفعيل اشتراكك في باقة {$planName} بنجاح.")
            ->line("تاريخ البدء: {$startsAt}")
            ->line("تاريخ الانتهاء: {$endsAt}")
            ->action('عرض اشتراكي', url('/subscription/my-subscription'))
            ->line('شكراً لاستخدامك منصتنا!');
    }

    public function toArray(object $notifiable): array
    {
        $planName = $this->subscription->plan->name ?? 'غير محدد';

        return [
            'type' => 'subscription_activated',
            'title' => 'تم الاشتراك في باقة جديدة',
            'message' => "تم تفعيل اشتراكك في باقة {$planName} بنجاح.",
            'subscription_id' => $this->subscription->id,
            'plan_id' => $this->subscription->plan_id,
            'plan_name' => $planName,
            'status' => $this->subscription->status,
            'starts_at' => $this->subscription->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $this->subscription->ends_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => $data['title'],
            'body' => $data['message'],
            'type' => $data['type'],
        ]);

        return new DatabaseMessage($data);
    }
}
