<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\WebinarRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WebinarRegisteredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public WebinarRegistration $registration)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $webinar = $this->registration->webinar;
        $startsAt = $webinar?->start_at?->timezone(config('app.timezone'))?->format('Y-m-d H:i');

        return (new MailMessage)
            ->subject('تم تأكيد تسجيلك في الويبنار | ' . ($webinar?->title ?? 'Skillso'))
            ->greeting('مرحباً ' . ($notifiable->name ?? ''))
            ->line('تم تأكيد تسجيلك بنجاح.')
            ->line('الويبنار: ' . ($webinar?->title ?? ''))
            ->line($startsAt ? 'الموعد: ' . $startsAt : 'سيتم إرسال تفاصيل الموعد قريباً.')
            ->line('ستصلك تعليمات الحضور قبل الموعد. لا يتضمن هذا الإشعار روابط دخول خاصة.')
            ->action('صفحة الويبنار', url('/webinar/' . ($webinar?->slug ?? '')));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $webinar = $this->registration->webinar;

        return [
            'title' => 'تم تأكيد تسجيلك في الويبنار',
            'title_ar' => 'تم تأكيد تسجيلك في الويبنار',
            'message' => 'تم حجز مقعدك في: ' . ($webinar?->title ?? 'ويبنار Skillso'),
            'message_ar' => 'تم حجز مقعدك في: ' . ($webinar?->title ?? 'ويبنار Skillso'),
            'action_url' => '/webinar/' . ($webinar?->slug ?? ''),
            'type' => 'webinar_registered',
            'webinar_id' => $webinar?->id,
            'webinar_slug' => $webinar?->slug,
        ];
    }
}
