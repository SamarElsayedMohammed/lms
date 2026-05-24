<?php

namespace App\Notifications;

use App\Models\WithdrawalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;

class WithdrawalStatusNotification extends Notification
{
    use Queueable, PushesToFirebase;

    protected $withdrawalRequest;

    public function __construct(WithdrawalRequest $withdrawalRequest)
    {
        $this->withdrawalRequest = $withdrawalRequest;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = ucfirst($this->withdrawalRequest->status);
        $amount = $this->withdrawalRequest->amount;
        
        $message = (new MailMessage)
                    ->subject('Withdrawal Request Status Updated: ' . $status)
                    ->line('Your withdrawal request for ' . $amount . ' EGP has been ' . $status . '.');

        if ($this->withdrawalRequest->status === 'rejected') {
            $message->line('Reason: ' . ($this->withdrawalRequest->rejection_reason ?? 'No reason provided.'));
        }

        return $message->action('View My Wallet', url('/my-wallet'))
                    ->line('Thank you for using our platform!');
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
