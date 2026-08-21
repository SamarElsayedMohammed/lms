<?php

namespace App\Observers;

use App\Jobs\RecalculateCourseDurationJob;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;

class LectureObserver
{
    /**
     * Handle the CourseChapterLecture "saved" event.
     */
    public function saved(CourseChapterLecture $lecture): void
    {
        if ($lecture->wasChanged(['duration_seconds', 'hours', 'minutes', 'seconds', 'is_active'])) {
            $this->dispatchRecalculateJob($lecture);
        }
    }

    /**
     * Handle the CourseChapterLecture "deleted" event.
     */
    public function deleted(CourseChapterLecture $lecture): void
    {
        $this->dispatchRecalculateJob($lecture);
    }

    /**
     * Handle the CourseChapterLecture "restored" event.
     */
    public function restored(CourseChapterLecture $lecture): void
    {
        $this->dispatchRecalculateJob($lecture);
    }

    /**
     * Dispatch the recalculation job.
     */
    protected function dispatchRecalculateJob(CourseChapterLecture $lecture): void
    {
        if ($lecture->chapter && $lecture->chapter->course_id) {
            RecalculateCourseDurationJob::dispatch($lecture->chapter->course_id);
        }
    }
}
