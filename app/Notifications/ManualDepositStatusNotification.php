<?php

namespace App\Notifications;

use App\Models\ManualDeposit;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class ManualDepositStatusNotification extends Notification
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
        $status = ucfirst($this->deposit->status);
        $message = (new MailMessage)
            ->subject("Update on your Deposit Request: {$status}")
            ->line("Your deposit request of {$this->deposit->amount} EGP via {$this->deposit->method->name} has been {$this->deposit->status}.");

        if ($this->deposit->status === 'approved') {
            $message->line("The amount has been added to your wallet balance.");
        } elseif ($this->deposit->status === 'rejected') {
            $message->line("Reason: " . ($this->deposit->admin_notes ?? 'No reason provided.'));
        }

        return $message->action('View My Wallet', url('/wallet'))
            ->line('Thank you for using our platform!');
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
