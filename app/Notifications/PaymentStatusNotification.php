<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class PaymentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    public function __construct(
        public bool $isSuccess,
        public string $itemName,
        public float $amount,
        public string $transactionId = '',
        public string $paymentMethod = 'Kashier',
        public ?string $failReason = null,
        public ?string $invoiceUrl = null,
        public ?string $retryUrl = null
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage);

        if ($this->isSuccess) {
            $message->subject("تم الدفع بنجاح | Payment Successful")
                ->view('emails.payment-success', [
                    'userName' => $notifiable->name,
                    'itemName' => $this->itemName,
                    'amount' => number_format($this->amount, 2),
                    'transactionId' => $this->transactionId ?: 'N/A',
                    'paymentMethod' => $this->paymentMethod,
                    'invoiceUrl' => $this->invoiceUrl ?: url('/'),
                ]);
        } else {
            $message->subject("فشلت عملية الدفع | Payment Failed")
                ->view('emails.payment-failed', [
                    'userName' => $notifiable->name,
                    'failReason' => $this->failReason ?? 'حدث خطأ أثناء معالجة الدفع.',
                    'retryUrl' => $this->retryUrl ?: url('/'),
                ]);
        }

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_status',
            'title' => $this->isSuccess ? 'تم الدفع بنجاح' : 'فشلت عملية الدفع',
            'message' => $this->isSuccess 
                ? "تم سداد مبلغ {$this->amount} EGP لـ {$this->itemName} بنجاح."
                : "لم نتمكن من إتمام عملية الدفع لـ {$this->itemName}.",
            'is_success' => $this->isSuccess,
            'amount' => $this->amount,
            'item_name' => $this->itemName,
            'transaction_id' => $this->transactionId,
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);
        $this->sendFcmNotification($notifiable, [
            'title' => $data['title'],
            'body' => $data['message'],
            'type' => 'payment_status',
        ]);
        
        return new DatabaseMessage($data);
    }
}
