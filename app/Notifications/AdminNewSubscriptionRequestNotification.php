<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

/**
 * Notifies admin users when a new manual subscription payment request is submitted.
 * Channels: database (in-app), mail, and Firebase FCM push notification.
 */
class AdminNewSubscriptionRequestNotification extends Notification
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    protected Subscription $subscription;
    protected User $requestingUser;

    public function __construct(Subscription $subscription, User $requestingUser)
    {
        $this->subscription  = $subscription;
        $this->requestingUser = $requestingUser;
    }

    /**
     * Delivery channels.
     */

    /**
     * Mail representation.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $planName  = $this->subscription->plan->name ?? 'غير محدد';
        $userName  = $this->requestingUser->name;
        $userEmail = $this->requestingUser->email;

        return (new MailMessage)
            ->subject("طلب اشتراك يدوي جديد - {$planName}")
            ->greeting("مرحباً {$notifiable->name}!")
            ->line("قام المستخدم **{$userName}** ({$userEmail}) بإرسال طلب اشتراك يدوي في باقة **{$planName}**.")
            ->line('يرجى مراجعة إيصال الدفع والموافقة على الطلب أو رفضه.')
            ->action('مراجعة الطلبات اليدوية', url('/admin/manual-subscriptions'))
            ->line('شكراً لمتابعتكم الدائمة!');
    }

    /**
     * Array / database representation.
     */
    public function toArray(object $notifiable): array
    {
        $planName = $this->subscription->plan->name ?? 'غير محدد';
        $userName = $this->requestingUser->name;

        return [
            'type'            => 'admin_new_subscription_request',
            'title'           => 'طلب اشتراك يدوي جديد',
            'message'         => "المستخدم {$userName} طلب الاشتراك في باقة {$planName} وينتظر مراجعة الإدارة.",
            'subscription_id' => $this->subscription->id,
            'plan_name'       => $planName,
            'user_id'         => $this->requestingUser->id,
            'user_name'       => $userName,
            'user_email'      => $this->requestingUser->email,
        ];
    }

    /**
     * Database channel — also triggers Firebase FCM push to the admin's devices.
     */
    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data     = $this->toArray($notifiable);
        $planName = $data['plan_name'];
        $userName = $data['user_name'];

        $this->sendFcmNotification($notifiable, [
            'title' => 'طلب اشتراك يدوي جديد',
            'body'  => "المستخدم {$userName} طلب الاشتراك في باقة {$planName}",
            'type'  => 'admin_new_subscription_request',
        ]);

        return new DatabaseMessage($data);
    }
}
