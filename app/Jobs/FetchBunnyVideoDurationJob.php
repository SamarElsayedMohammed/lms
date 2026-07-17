<?php

namespace App\Jobs;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchBunnyVideoDurationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 10;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600, 1800]; // 1m, 2m, 5m, 10m, 30m
    }

    public function __construct(
        public int $lectureId,
        public string $libraryId,
        public string $videoGuid
    ) {}

    public function handle(): void
    {
        $lecture = CourseChapterLecture::find($this->lectureId);
        if (!$lecture) {
            return;
        }

        $apiKey = config('services.bunny.api_key');
        if (empty($apiKey)) {
            Log::warning('Bunny API key not configured. Cannot fetch duration.', ['lecture_id' => $this->lectureId]);
            return;
        }

        $url = "https://video.bunnycdn.com/library/{$this->libraryId}/videos/{$this->videoGuid}";

        $response = Http::withHeaders([
            'AccessKey' => $apiKey,
            'Accept'    => 'application/json',
        ])->get($url);

        if (!$response->successful()) {
            Log::error('Failed to fetch Bunny video details', [
                'lecture_id' => $this->lectureId,
                'status'     => $response->status(),
                'body'       => $response->body()
            ]);
            
            if ($response->status() >= 500 || $response->status() === 429) {
                $this->release();
            }
            return;
        }

        $data = $response->json();
        $durationSeconds = $data['length'] ?? 0;

        // If the video is still processing, duration might be 0
        if ($durationSeconds <= 0 && in_array($data['status'] ?? -1, [0, 1, 2, 3])) { // 0: Created, 1: Uploaded, 2: Processing, 3: Transcoding
            Log::info('Bunny video is still processing. Retrying later.', ['lecture_id' => $this->lectureId]);
            $this->release();
            return;
        }

        if ($durationSeconds > 0) {
            $this->updateLectureDuration($lecture, (int) $durationSeconds);
        }
    }

    private function updateLectureDuration(CourseChapterLecture $lecture, int $durationSeconds): void
    {
        $hours = floor($durationSeconds / 3600);
        $minutes = floor(($durationSeconds % 3600) / 60);
        $seconds = $durationSeconds % 60;

        $lecture->update([
            'duration_seconds' => $durationSeconds,
            'hours'            => $hours,
            'minutes'          => $minutes,
            'seconds'          => $seconds,
        ]);

        // Recalculate parent course duration
        if ($lecture->chapter && $lecture->chapter->course) {
            $course = $lecture->chapter->course;
            $this->recalculateCourseDuration($course);
        }
    }

    private function recalculateCourseDuration(Course $course): void
    {
        $totalSeconds = CourseChapterLecture::whereHas('chapter', function ($query) use ($course) {
            $query->where('course_id', $course->id)
                  ->where('is_active', 1);
        })
        ->where('is_active', 1)
        ->sum('duration_seconds');

        $course->update(['duration_seconds' => $totalSeconds]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $lecture = CourseChapterLecture::find($this->lectureId);
        if ($lecture) {
            $lecture->update([
                'hls_status' => 'duration_failed',
                'hls_error_message' => 'Failed to fetch duration from Bunny API after max retries.',
            ]);
        }
    }
}
