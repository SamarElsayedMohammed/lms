<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Course\Course;
use App\Services\WebPageKnowledgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class IndexCourseKnowledgeUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(
        public int $courseId,
        public string $url,
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(WebPageKnowledgeService $pages): void
    {
        $course = Course::query()->find($this->courseId);
        $url = trim($this->url);
        if (! $course || $url === '') {
            return;
        }

        $page = $pages->extract($url);
        $course->update([
            'ai_knowledge_url' => $page['url'],
            'ai_knowledge_content' => $pages->joinTexts(
                trim((string) $course->ai_knowledge_content),
                $page['text'],
            ) ?: $page['text'],
        ]);
        $pages->indexCourse($course->fresh() ?? $course, $page);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Course knowledge URL indexing failed', [
            'course_id' => $this->courseId,
            'url' => $this->url,
            'error' => $exception->getMessage(),
        ]);
    }
}
