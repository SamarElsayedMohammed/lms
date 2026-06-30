<?php

namespace App\Notifications;

use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\PushesToFirebase;
use App\Traits\ConfigurableNotification;

class NewCourseNotification extends Notification implements ShouldQueue
{
    use Queueable, PushesToFirebase, ConfigurableNotification;

    protected $course;

    public function __construct(Course $course)
    {
        $this->course = $course;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تم إضافة كورس جديد | New Course Available')
            ->view('emails.general-notification', [
                'notificationTitle' => 'تم إضافة كورس جديد!',
                'greeting' => "مرحباً {$notifiable->name}،",
                'notificationContent' => "نود إعلامك بأنه تم نشر كورس جديد على المنصة بعنوان: <strong>{$this->course->title}</strong>.<br><br>لا تفوت فرصة الاستفادة من هذا المحتوى الرائع وبادر بالتسجيل الآن!",
                'actionUrl' => url('/courses/' . $this->course->slug),
                'actionText' => 'عرض تفاصيل الكورس',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'course_id' => $this->course->id,
            'title' => 'New Course Released!',
            'title_ar' => 'تم نشر كورس جديد!',
            'message' => 'New course available: ' . $this->course->title,
            'message_ar' => 'كورس جديد متوفر الآن: ' . $this->course->title,
            'thumbnail' => $this->course->thumbnail,
            'action_url' => '/courses/' . $this->course->slug,
            'type' => 'new_course'
        ];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = $this->toArray($notifiable);

        $this->sendFcmNotification($notifiable, [
            'title' => $data['title_ar'] ?? $data['title'],
            'body' => $data['message_ar'] ?? $data['message'],
            'type' => $data['type'],
        ]);
        
        return new DatabaseMessage($data);
    }
}
