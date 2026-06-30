<?php

namespace App\Notifications;

use App\Models\ManualDeposit;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

/**
 * Notifies admin users when a new manual wallet deposit request is submitted.
 * Channels: database (in-app), mail, and Firebase FCM push notification.
 */
class AdminNewManualDepositNotification extends Notification
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    protected ManualDeposit $deposit;
    protected User $requestingUser;

    public function __construct(ManualDeposit $deposit, User $requestingUser)
    {
        $this->deposit        = $deposit;
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
        $userName   = $this->requestingUser->name;
        $userEmail  = $this->requestingUser->email;
        $amount     = number_format((float) $this->deposit->amount, 2);
        $methodName = $this->deposit->method->name ?? 'غير محدد';

        return (new MailMessage)
            ->subject("طلب إيداع يدوي جديد - {$amount} EGP")
            ->view('emails.admin-notification', [
                'notificationTitle' => 'طلب إيداع يدوي جديد',
                'notificationContent' => "قام المستخدم <strong>{$userName}</strong> ({$userEmail}) بإرسال طلب إيداع يدوي في المحفظة.<br><br>المبلغ: <strong>{$amount} EGP</strong> عبر <strong>{$methodName}</strong>.<br><br>يرجى مراجعة إيصال الدفع والموافقة على الطلب أو رفضه.",
                'senderName' => $userName,
                'actionUrl' => url('/admin/manual-deposits'),
                'actionText' => 'مراجعة طلبات الإيداع',
            ]);
    }

    /**
     * Array / database representation.
     */
    public function toArray(object $notifiable): array
    {
        $userName   = $this->requestingUser->name;
        $amount     = number_format((float) $this->deposit->amount, 2);
        $methodName = $this->deposit->method->name ?? 'غير محدد';

        return [
            'type'       => 'admin_new_manual_deposit',
            'title'      => 'طلب إيداع يدوي جديد',
            'message'    => "المستخدم {$userName} طلب إيداع {$amount} EGP عبر {$methodName} وينتظر مراجعة الإدارة.",
            'deposit_id' => $this->deposit->id,
            'amount'     => $this->deposit->amount,
            'method'     => $methodName,
            'user_id'    => $this->requestingUser->id,
            'user_name'  => $userName,
            'user_email' => $this->requestingUser->email,
        ];
    }

    /**
     * Database channel — also triggers Firebase FCM push to the admin's devices.
     */
    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data       = $this->toArray($notifiable);
        $amount     = number_format((float) $this->deposit->amount, 2);
        $userName   = $data['user_name'];

        $this->sendFcmNotification($notifiable, [
            'title' => 'طلب إيداع يدوي جديد',
            'body'  => "المستخدم {$userName} طلب إيداع {$amount} EGP في المحفظة",
            'type'  => 'admin_new_manual_deposit',
        ]);

        return new DatabaseMessage($data);
    }
}
