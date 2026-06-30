<?php

namespace App\Notifications;

use App\Models\WithdrawalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class WithdrawalStatusNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    protected $withdrawalRequest;

    public function __construct(WithdrawalRequest $withdrawalRequest)
    {
        $this->withdrawalRequest = $withdrawalRequest;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = $this->withdrawalRequest->status;
        $statusAr = $status === 'approved' ? 'مقبول' : ($status === 'rejected' ? 'مرفوض' : $status);
        $amount = $this->withdrawalRequest->amount;
        
        $message = "لقد تم تحديث حالة طلب السحب الخاص بك بقيمة <strong>{$amount} EGP</strong> إلى: <strong>{$statusAr}</strong>.";

        if ($status === 'rejected') {
            $reason = $this->withdrawalRequest->rejection_reason ?? 'لا يوجد سبب محدد.';
            $message .= "<br><br><strong>سبب الرفض:</strong> {$reason}";
        }

        return (new MailMessage)
            ->subject("تحديث حالة طلب السحب | Withdrawal Request Status")
            ->view('emails.general-notification', [
                'notificationTitle' => 'تحديث حالة طلب السحب',
                'greeting' => "مرحباً {$notifiable->name}،",
                'notificationContent' => $message,
                'actionUrl' => url('/my-wallet'),
                'actionText' => 'عرض المحفظة',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $status = $this->withdrawalRequest->status;
        $statusAr = $status === 'approved' ? 'مقبول' : ($status === 'rejected' ? 'مرفوض' : $status);

        return [
            'withdrawal_id' => $this->withdrawalRequest->id,
            'amount' => $this->withdrawalRequest->amount,
            'status' => $status,
            'title' => 'Withdrawal Request ' . ucfirst($status),
            'title_ar' => 'طلب السحب ' . $statusAr,
            'message' => 'Your withdrawal request of ' . $this->withdrawalRequest->amount . ' EGP is ' . $status . '.',
            'message_ar' => 'تم ' . $statusAr . ' طلب السحب الخاص بك بمبلغ ' . $this->withdrawalRequest->amount . ' جنيه.',
            'type' => 'withdrawal'
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
