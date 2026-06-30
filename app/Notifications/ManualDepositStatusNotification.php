<?php

namespace App\Notifications;

use App\Models\ManualDeposit;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class ManualDepositStatusNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    protected $deposit;

    /**
     * Create a new notification instance.
     */
    public function __construct(ManualDeposit $deposit)
    {
        $this->deposit = $deposit;
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
        $statusAr = $this->deposit->status === 'approved' ? 'مقبول' : 'مرفوض';
        $message = (new MailMessage)->subject("تحديث حالة طلب الإيداع: {$statusAr} | Deposit Status");

        if ($this->deposit->status === 'approved') {
            $message->view('emails.manual-payment-approved', [
                'userName' => $notifiable->name,
                'subtitle' => "مرحباً {$notifiable->name}، يسعدنا إخبارك بأنه تمت مراجعة إيصال التحويل البنكي الخاص بك بنجاح. لقد تم شحن محفظتك بمبلغ {$this->deposit->amount} EGP.",
                'actionUrl' => url('/wallet'),
            ]);
        } else {
            $message->view('emails.manual-payment-rejected', [
                'userName' => $notifiable->name,
                'subtitle' => "مرحباً {$notifiable->name}، لقد قمنا بمراجعة إيصال التحويل البنكي الخاص بك لإيداع مبلغ {$this->deposit->amount} EGP، ولكن للأسف لم نتمكن من قبوله.",
                'rejectReason' => $this->deposit->admin_notes ?? 'لا يوجد سبب محدد.',
                'uploadUrl' => url('/wallet/deposit'),
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
            'deposit_id' => $this->deposit->id,
            'amount' => $this->deposit->amount,
            'status' => $this->deposit->status,
            'message' => "Your deposit of {$this->deposit->amount} EGP was {$this->deposit->status}.",
        ];
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);
        $this->sendFcmNotification($notifiable, [
            'title' => 'Deposit Request ' . ucfirst($this->deposit->status),
            'body' => $data['message'],
            'type' => 'manual_deposit',
        ]);
        return new DatabaseMessage($data);
    }
}
