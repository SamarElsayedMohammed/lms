<?php

namespace App\Notifications;

use App\Models\Commission;
use App\Services\HelperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommissionPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $commission;

    /**
     * Create a new notification instance.
     */
    public function __construct(Commission $commission)
    {
        $this->commission = $commission;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database']; // Only database notifications for now
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $currencySymbol = HelperService::systemSettings('currency_symbol') ?? '$';

        return (new MailMessage())
            ->subject('Commission Payment Received')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('You have received a commission payment.')
            ->line('Course: ' . $this->commission->course->title)
            ->line('Order Number: #' . $this->commission->order->order_number)
            ->line('Commission Amount: '
            . $currencySymbol
            . number_format($this->commission->instructor_commission_amount, 2))
            ->line('The amount has been credited to your wallet.')
            ->action('View Commission Details', url('/instructor/commissions'))
            ->line('Thank you for being part of our platform!');
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
        return new DatabaseMessage($this->toArray($notifiable));
    }
}
