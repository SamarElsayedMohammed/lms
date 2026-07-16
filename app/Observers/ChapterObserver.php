<?php

namespace App\Observers;

use App\Jobs\RecalculateCourseDurationJob;
use App\Models\Course\CourseChapter\CourseChapter;

class ChapterObserver
{
    /**
     * Handle the CourseChapter "saved" event.
     */
    public function saved(CourseChapter $chapter): void
    {
        $this->dispatchRecalculateJob($chapter);
    }

    /**
     * Handle the CourseChapter "deleted" event.
     */
    public function deleted(CourseChapter $chapter): void
    {
        $this->dispatchRecalculateJob($chapter);
    }

    /**
     * Handle the CourseChapter "restored" event.
     */
    public function restored(CourseChapter $chapter): void
    {
        $this->dispatchRecalculateJob($chapter);
    }

    /**
     * Dispatch the recalculation job.
     */
    protected function dispatchRecalculateJob(CourseChapter $chapter): void
    {
        if ($chapter->course_id) {
            RecalculateCourseDurationJob::dispatch($chapter->course_id);
        }
    }
}
