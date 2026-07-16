<?php

namespace App\Providers;

use App\Events\CurriculumItemCompleted;
use App\Listeners\UpdateCourseProgressListener;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        CurriculumItemCompleted::class => [
            UpdateCourseProgressListener::class,
        ],
        \App\Events\WebinarRegistered::class => [
            \App\Listeners\SendWebinarConfirmationMail::class,
        ],
        \App\Events\WebinarCancelled::class => [
            \App\Listeners\NotifyUsersOfCancellation::class,
        ],
        \App\Events\WebinarStartingSoon::class => [
            \App\Listeners\SendWebinarReminder::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    #[\Override]
    public function boot(): void
    {
        \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::observe(\App\Observers\LectureObserver::class);
        \App\Models\Course\CourseChapter\CourseChapter::observe(\App\Observers\ChapterObserver::class);
    }
}
