<?php

namespace App\Listeners;

use App\Events\CurriculumItemCompleted;
use App\Services\CourseProgressService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateCourseProgressListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly CourseProgressService $progressService,
    ) {}

    public function handle(CurriculumItemCompleted $event): void
    {
        $this->progressService->calculateAndUpdateProgress(
            $event->userId,
            $event->courseId
        );
    }
}
