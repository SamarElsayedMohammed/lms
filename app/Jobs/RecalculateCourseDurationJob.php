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

    protected int $courseId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $courseId)
    {
        $this->courseId = $courseId;
        $this->onQueue('low'); // low priority queue
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

        // 1. Recalculate all chapters first
        $chapters = $course->chapters()->get();
        foreach ($chapters as $chapter) {
            $chapter->recalculateDuration();
        }

        // 2. Recalculate course
        $course->recalculateDuration();
    }
}
