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
 * Notifies the user when their manual subscription renewal request is submitted and awaiting admin review.
 */
class ManualRenewalRequestedNotification extends Notification
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    public function __construct(protected Subscription $subscription) {}

    public function toMail(object $notifiable): MailMessage
    {
        $planName = $this->subscription->plan->name ?? 'غير محدد';

        return (new MailMessage)
            ->subject("طلب تجديد اشتراكك قيد المراجعة - {$planName}")
            ->view('emails.general-notification', [
                'notificationTitle' => 'تجديد الاشتراك قيد المراجعة',
                'greeting' => "مرحباً {$notifiable->name}،",
                'notificationContent' => "تم استلام طلب تجديد اشتراكك في باقة <strong>{$planName}</strong> بنجاح.<br>سيقوم فريقنا بمراجعة إيصال الدفع والرد عليك في أقرب وقت ممكن.",
                'actionUrl' => url('/subscription/my-subscription'),
                'actionText' => 'عرض تفاصيل اشتراكي',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $planName = $this->subscription->plan->name ?? 'غير محدد';

        return [
            'type'            => 'subscription',
            'title'           => 'طلب تجديد الاشتراك قيد المراجعة',
            'message'         => "تم استلام طلب تجديد اشتراكك في باقة {$planName} وهو قيد المراجعة.",
            'subscription_id' => $this->subscription->id,
            'plan_name'       => $planName,
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data     = $this->toArray($notifiable);
        $planName = $data['plan_name'];

        $this->sendFcmNotification($notifiable, [
            'title' => 'طلب تجديد قيد المراجعة',
            'body'  => "طلب تجديد اشتراكك في باقة {$planName} قيد المراجعة.",
            'type'  => 'subscription',
        ]);

        return new DatabaseMessage($data);
    }
}
