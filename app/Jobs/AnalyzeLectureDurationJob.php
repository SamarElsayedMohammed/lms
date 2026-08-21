<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class AnalyzeLectureDurationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public int|array $backoff = [10, 30];

    public function __construct(public int $lectureId)
    {
        $this->onQueue('video-encoding');
    }

    public function handle(): void
    {
        $lecture = CourseChapterLecture::with('chapter:id,course_id')->find($this->lectureId);
        $file = $lecture?->getRawOriginal('file');

        if (!$lecture || empty($file) || !Storage::disk('public')->exists($file)) {
            return;
        }

        try {
            $fileInfo = (new \getID3())->analyze(Storage::disk('public')->path($file));
            $totalSeconds = (int) round($fileInfo['playtime_seconds'] ?? 0);

            if ($totalSeconds <= 0) {
                return;
            }

            $lecture->updateQuietly([
                'duration_seconds' => $totalSeconds,
                'hours' => (int) floor($totalSeconds / 3600),
                'minutes' => (int) floor(($totalSeconds % 3600) / 60),
                'seconds' => $totalSeconds % 60,
            ]);

            if ($lecture->chapter?->course_id) {
                RecalculateCourseDurationJob::dispatch($lecture->chapter->course_id);
            }
        } catch (\Throwable $exception) {
            Log::warning('Could not analyze uploaded video duration', [
                'lecture_id' => $this->lectureId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
