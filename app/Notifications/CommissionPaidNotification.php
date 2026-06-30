<?php

namespace App\Notifications;

use App\Models\Commission;
use App\Services\HelperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class CommissionPaidNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    protected $commission;
    protected array $defaultChannels = ['database', 'mail'];

    /**
     * Create a new notification instance.
     */
    public function __construct(Commission $commission)
    {
        $this->commission = $commission;
    }



    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $currencySymbol = HelperService::systemSettings('currency_symbol') ?? 'EGP';
        $amount = number_format($this->commission->instructor_commission_amount, 2);

        $message = "لقد تلقيت عمولة جديدة بقيمة <strong>{$amount} {$currencySymbol}</strong>.<br><br>";
        $message .= "<strong>تفاصيل العملية:</strong><br>";
        $message .= "الكورس: {$this->commission->course->title}<br>";
        $message .= "رقم الطلب: #{$this->commission->order->order_number}<br><br>";
        $message .= "تم إضافة المبلغ إلى رصيد محفظتك بنجاح.";

        return (new MailMessage())
            ->subject('إيداع عمولة جديدة | Commission Paid')
            ->view('emails.general-notification', [
                'notificationTitle' => 'إيداع عمولة جديدة',
                'greeting' => "مرحباً {$notifiable->name}،",
                'notificationContent' => $message,
                'actionUrl' => url('/instructor/commissions'),
                'actionText' => 'عرض تفاصيل العمولات',
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $currencySymbol = HelperService::systemSettings('currency_symbol') ?? '$';

        return [
            'type' => 'commission_paid',
            'title' => 'Commission Payment Received',
            'message' =>
                'You received '
                . $currencySymbol
                . number_format($this->commission->instructor_commission_amount, 2)
                . ' commission for course: '
                . $this->commission->course->title,
            'commission_id' => $this->commission->id,
            'course_id' => $this->commission->course_id,
            'order_id' => $this->commission->order_id,
            'amount' => $this->commission->instructor_commission_amount,
            'course_title' => $this->commission->course->title,
            'order_number' => $this->commission->order->order_number,
        ];
    }

    /**
     * Get the database representation of the notification.
     */
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
