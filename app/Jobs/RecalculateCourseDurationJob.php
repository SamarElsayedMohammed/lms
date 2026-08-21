<?php

namespace App\Jobs;

use App\Models\Course\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateCourseDurationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int|array $backoff = [10, 30, 60];

    public int $courseId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $courseId)
    {
        $this->courseId = $courseId;
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $course = Course::find($this->courseId);
        if (!$course) {
            return;
        }

        // 1. Recalculate all chapters first (without redundant eager course recalculation per chapter)
        $chapters = $course->chapters()->get();
        foreach ($chapters as $chapter) {
            $chapter->recalculateDuration(false);
        }

        // 2. Recalculate course
        $course->recalculateDuration();
    }
}
