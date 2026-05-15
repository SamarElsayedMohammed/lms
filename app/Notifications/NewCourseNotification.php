<?php

namespace App\Notifications;

use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewCourseNotification extends Notification
{
    use Queueable;

    protected $course;

    public function __construct(Course $course)
    {
        $this->course = $course;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject('New Course Available: ' . $this->course->title)
                    ->line('A new course has been released on Skillso!')
                    ->line('Course Title: ' . $this->course->title)
                    ->action('View Course', url('/courses/' . $this->course->slug))
                    ->line('Don\'t miss out on this learning opportunity!');
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
}
