<?php

namespace App\Listeners;

use App\Events\CurriculumItemCompleted;
use App\Services\CourseProgressService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateCourseProgressListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 60;

    public int|array $backoff = [10, 30];

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
