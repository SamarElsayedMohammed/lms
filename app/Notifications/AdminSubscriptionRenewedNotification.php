<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

/**
 * Notifies admins when a user successfully renews their subscription (immediate / wallet payment).
 */
class AdminSubscriptionRenewedNotification extends Notification
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    public function __construct(
        protected Subscription $subscription,
        protected User $subscriber,
        protected float $amountPaid = 0.0
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $planName  = $this->subscription->plan->name ?? 'غير محدد';
        $userName  = $this->subscriber->name;
        $userEmail = $this->subscriber->email;

        return (new MailMessage)
            ->subject("تجديد اشتراك - {$planName}")
            ->view('emails.admin-notification', [
                'notificationTitle' => 'تجديد اشتراك ناجح',
                'notificationContent' => "قام المستخدم <strong>{$userName}</strong> ({$userEmail}) بتجديد اشتراكه في باقة <strong>{$planName}</strong> بنجاح.<br><br>المبلغ المسدَّد: <strong>{$this->amountPaid} EGP</strong>",
                'senderName' => $userName,
                'actionUrl' => url('/admin/subscriptions'),
                'actionText' => 'إدارة الاشتراكات',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $planName = $this->subscription->plan->name ?? 'غير محدد';
        $userName = $this->subscriber->name;

        return [
            'type'            => 'admin_new_subscription_request',
            'title'           => 'تجديد اشتراك ناجح',
            'message'         => "المستخدم {$userName} جدَّد اشتراكه في باقة {$planName} بنجاح.",
            'subscription_id' => $this->subscription->id,
            'plan_name'       => $planName,
            'user_id'         => $this->subscriber->id,
            'user_name'       => $userName,
            'user_email'      => $this->subscriber->email,
            'amount_paid'     => $this->amountPaid,
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data     = $this->toArray($notifiable);
        $planName = $data['plan_name'];
        $userName = $data['user_name'];

        $this->sendFcmNotification($notifiable, [
            'title' => 'تجديد اشتراك',
            'body'  => "المستخدم {$userName} جدَّد اشتراكه في باقة {$planName}.",
            'type'  => 'admin_new_subscription_request',
        ]);

        return new DatabaseMessage($data);
    }
}
