<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class ManualSubscriptionStatusNotification extends Notification
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    protected $subscription;

    /**
     * Create a new notification instance.
     */
    public function __construct(Subscription $subscription)
    {
        $this->subscription = $subscription;
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
        $status = $this->subscription->status;
        $planName = $this->subscription->plan->name;
        
        $subject = $status === 'active' || $status === 'pending'
            ? "تم تفعيل اشتراكك في باقة: {$planName}"
            : "تم رفض طلب الاشتراك في باقة: {$planName}";

        $message = (new MailMessage)
            ->subject($subject);

        if ($status === 'active' || $status === 'pending') {
            $message->line("تهانينا! تم تفعيل اشتراكك في باقة {$planName} بنجاح.")
                ->line("تاريخ البدء: " . $this->subscription->starts_at->format('Y-m-d H:i:s'))
                ->line($this->subscription->ends_at ? "تاريخ الانتهاء: " . $this->subscription->ends_at->format('Y-m-d H:i:s') : "نوع الاشتراك: مدى الحياة");
        } else {
            $payment = $this->subscription->payments()->orderBy('id', 'desc')->first();
            $adminNotes = $payment?->admin_notes ?? 'لا يوجد سبب محدد.';
            $message->line("نأسف لإبلاغك بأنه قد تم رفض طلب الاشتراك في باقة {$planName}.")
                ->line("السبب: " . $adminNotes);
        }

        return $message->action('عرض اشتراكي', url('/subscription/my-subscription'))
            ->line('شكراً لاستخدامك منصتنا!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $status = $this->subscription->status;
        $planName = $this->subscription->plan->name;

        $msg = $status === 'active' || $status === 'pending'
            ? "تمت الموافقة على طلب اشتراكك في باقة {$planName} وتفعيلها."
            : "تم رفض طلب اشتراكك في باقة {$planName}.";

        return [
            'subscription_id' => $this->subscription->id,
            'plan_name' => $planName,
            'status' => $status,
            'message' => $msg,
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);
        $title = $data['status'] === 'active' || $data['status'] === 'pending'
            ? "تفعيل اشتراك: " . $data['plan_name']
            : "رفض اشتراك: " . $data['plan_name'];

        $this->sendFcmNotification($notifiable, [
            'title' => $title,
            'body' => $data['message'],
            'type' => 'manual_subscription_status',
        ]);
        
        return new DatabaseMessage($data);
    }
}
