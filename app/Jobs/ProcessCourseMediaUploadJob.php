<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Lecture\LectureResource;
use App\Services\BunnyStreamService;
use App\Services\CourseMediaStaging;
use App\Services\DocumentParserService;
use App\Services\FileService;
use App\Services\WebPageKnowledgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessCourseMediaUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7000;

    public int $backoff = 30;

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public int $courseId,
        public array $items,
        public string $batchId = '',
        public int $batchSize = 1,
    ) {
        $this->onQueue('video-encoding');
    }

    public function handle(): void
    {
        $course = Course::query()->find($this->courseId);
        if (! $course) {
            $this->cleanup();

            return;
        }

        $course->update(['media_upload_status' => 'processing']);

        $itemFailed = false;
        foreach ($this->items as $item) {
            $path = is_string($item['path'] ?? null) ? $item['path'] : '';
            if ($path === '' || ! is_file($path)) {
                $itemFailed = true;
                Log::warning('Course media item missing on disk', [
                    'course_id' => $this->courseId,
                    'kind' => $item['kind'] ?? null,
                    'path' => $path,
                ]);
                continue;
            }

            $bytes = (int) (@filesize($path) ?: 0);
            $started = microtime(true);
            try {
                $file = CourseMediaStaging::uploadedFile([
                    'path' => $path,
                    'original_name' => is_string($item['original_name'] ?? null) ? $item['original_name'] : basename($path),
                    'mime' => is_string($item['mime'] ?? null) ? $item['mime'] : 'application/octet-stream',
                ]);

                match ((string) ($item['kind'] ?? '')) {
                    'thumbnail' => $this->storeThumbnail($course, $file, $item),
                    'intro' => $this->storeIntro($course, $file, $item),
                    'lecture' => $this->storeLecture($file, $item),
                    'material' => $this->storeMaterial($file, $item),
                    'knowledge' => $this->storeKnowledge($course, $file, $item),
                    default => null,
                };

                CourseMediaStaging::delete($path);
                Log::info('Course media item uploaded', [
                    'course_id' => $this->courseId,
                    'kind' => $item['kind'] ?? null,
                    'lecture_id' => $item['lecture_id'] ?? null,
                    'bytes' => $bytes,
                    'seconds' => round(microtime(true) - $started, 2),
                ]);
            } catch (Throwable $e) {
                $itemFailed = true;
                Log::error('Course media item failed', [
                    'course_id' => $this->courseId,
                    'kind' => $item['kind'] ?? null,
                    'lecture_id' => $item['lecture_id'] ?? null,
                    'bytes' => $bytes,
                    'seconds' => round(microtime(true) - $started, 2),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->finishBatch($itemFailed);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Course media upload job failed', [
            'course_id' => $this->courseId,
            'error' => $exception->getMessage(),
        ]);
        $this->finishBatch(true);
    }

    private function finishBatch(bool $itemFailed): void
    {
        $course = Course::query()->find($this->courseId);
        if (! $course) {
            return;
        }

        if ($this->batchId === '' || $this->batchSize <= 1) {
            $this->refreshCourseDuration($course);
            $course->update(['media_upload_status' => $itemFailed ? 'failed' : 'ready']);

            return;
        }

        $doneKey = 'course-media:'.$this->batchId.':done';
        $failKey = 'course-media:'.$this->batchId.':failed';
        if ($itemFailed) {
            Cache::put($failKey, 1, now()->addDay());
        }
        Cache::add($doneKey, 0, now()->addDay());
        $done = (int) Cache::increment($doneKey);
        if ($done < $this->batchSize) {
            return;
        }

        $course = Course::query()->find($this->courseId);
        if (! $course) {
            return;
        }
        $this->refreshCourseDuration($course);
        $failed = (bool) Cache::pull($failKey);
        Cache::forget($doneKey);
        $course->update(['media_upload_status' => $failed ? 'failed' : 'ready']);
    }

    private function refreshCourseDuration(Course $course): void
    {
        $course->chapters()->get()->each(function ($chapter): void {
            $chapter->recalculateDuration(false);
        });
        $course->recalculateDuration();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function storeThumbnail(Course $course, \Illuminate\Http\UploadedFile $file, array $item): void
    {
        $folder = is_string($item['folder'] ?? null) ? $item['folder'] : FileService::coursePath($course->slug, 'thumbnail');
        $stored = FileService::compressAndUpload($file, $folder);
        $previous = is_string($item['previous'] ?? null) ? $item['previous'] : null;
        if ($previous && $previous !== $stored) {
            FileService::delete($previous);
        }
        $course->update(['thumbnail' => $stored]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function storeIntro(Course $course, \Illuminate\Http\UploadedFile $file, array $item): void
    {
        $title = is_string($item['title'] ?? null) && $item['title'] !== '' ? $item['title'] : $course->title;
        $stored = BunnyStreamService::storeUploadedVideo(
            $file,
            $title,
            is_string($item['folder'] ?? null) ? $item['folder'] : FileService::coursePath($course->slug, 'intro'),
            is_string($item['collection'] ?? null) ? $item['collection'] : FileService::courseFolderSegment($course->slug),
        );
        $previous = is_string($item['previous'] ?? null) ? $item['previous'] : null;
        if ($previous && $previous !== $stored['value']) {
            FileService::delete($previous);
        }
        $course->update([
            'intro_video' => $stored['value'],
            'intro_video_type' => $stored['type'] === 'url' ? 'url' : 'file',
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function storeLecture(\Illuminate\Http\UploadedFile $file, array $item): void
    {
        $lectureId = (int) ($item['lecture_id'] ?? 0);
        $lecture = CourseChapterLecture::query()->find($lectureId);
        if (! $lecture) {
            return;
        }

        [$hours, $minutes, $seconds] = $this->durationFromFile($file, (int) $lecture->hours, (int) $lecture->minutes, (int) $lecture->seconds);
        $stored = BunnyStreamService::storeUploadedVideo(
            $file,
            is_string($item['title'] ?? null) ? $item['title'] : (string) $lecture->title,
            is_string($item['folder'] ?? null) ? $item['folder'] : 'courses/lessons',
            is_string($item['collection'] ?? null) ? $item['collection'] : null,
        );

        if ($stored['type'] === 'url') {
            $lecture->update([
                'type' => 'youtube_url',
                'file' => null,
                'youtube_url' => $stored['value'],
                'hours' => $hours,
                'minutes' => $minutes,
                'seconds' => $seconds,
                'duration_seconds' => ($hours * 3600) + ($minutes * 60) + $seconds,
            ]);
            if (is_string($stored['library_id']) && is_string($stored['guid'])) {
                FetchBunnyVideoDurationJob::dispatch($lecture->id, $stored['library_id'], $stored['guid']);
            }

            return;
        }

        $lecture->update([
            'type' => 'file',
            'file' => $stored['value'],
            'file_extension' => $file->getClientOriginalExtension(),
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds,
            'duration_seconds' => ($hours * 3600) + ($minutes * 60) + $seconds,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function storeMaterial(\Illuminate\Http\UploadedFile $file, array $item): void
    {
        $resource = LectureResource::query()->find((int) ($item['resource_id'] ?? 0));
        if (! $resource) {
            return;
        }

        $resource->update([
            'type' => 'file',
            'file' => FileService::upload($file, is_string($item['folder'] ?? null) ? $item['folder'] : 'courses/materials'),
            'file_extension' => $file->getClientOriginalExtension(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function storeKnowledge(Course $course, \Illuminate\Http\UploadedFile $file, array $item): void
    {
        $folder = is_string($item['folder'] ?? null) ? $item['folder'] : FileService::coursePath($course->slug, 'knowledge');
        $text = '';
        $path = $file->getRealPath();
        if (is_string($path) && $path !== '') {
            $text = trim(app(DocumentParserService::class)->extractText($path, strtolower($file->getClientOriginalExtension())));
        }

        $course->update([
            'ai_knowledge_file' => FileService::upload($file, $folder),
            'ai_knowledge_content' => app(WebPageKnowledgeService::class)->joinTexts(
                trim((string) $course->ai_knowledge_content),
                $text,
            ) ?: $course->ai_knowledge_content,
        ]);
        $course->refresh();
        app(WebPageKnowledgeService::class)->indexCombined(
            $course,
            trim((string) $course->ai_knowledge_content),
            is_string($course->ai_knowledge_file) ? $course->ai_knowledge_file : null,
            is_string($course->ai_knowledge_url) ? $course->ai_knowledge_url : null,
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function durationFromFile(\Illuminate\Http\UploadedFile $file, int $hours, int $minutes, int $seconds): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['mp4', 'avi', 'mov', 'webm', 'mkv', 'flv', 'wmv'], true)) {
            return [$hours, $minutes, $seconds];
        }

        try {
            $fileInfo = (new \getID3())->analyze($file->getRealPath() ?: '');
            $totalSeconds = (int) round($fileInfo['playtime_seconds'] ?? 0);
            if ($totalSeconds > 0) {
                return [
                    (int) floor($totalSeconds / 3600),
                    (int) floor(($totalSeconds % 3600) / 60),
                    (int) ($totalSeconds % 60),
                ];
            }
        } catch (Throwable $e) {
            Log::warning('Could not read staged video duration', ['error' => $e->getMessage()]);
        }

        return [$hours, $minutes, $seconds];
    }

    private function cleanup(): void
    {
        foreach ($this->items as $item) {
            CourseMediaStaging::delete(is_string($item['path'] ?? null) ? $item['path'] : null);
        }
    }
}
