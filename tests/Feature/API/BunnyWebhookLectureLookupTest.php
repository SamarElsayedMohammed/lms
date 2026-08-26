<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Jobs\FetchBunnyVideoDurationJob;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BunnyWebhookLectureLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_finished_webhook_finds_file_type_lectures_not_only_type_video(): void
    {
        Queue::fake();
        Config::set('services.bunny.webhook_secret', 'test-api-key');

        $videoGuid = 'ed69b38c-94b7-4195-970b-6ded05193a44';
        $libraryId = '423625';
        $lecture = CourseChapterLecture::factory()->create([
            'type' => 'file',
            'file' => "https://iframe.mediadelivery.net/embed/{$libraryId}/{$videoGuid}",
            'file_extension' => 'mp4',
            'youtube_url' => null,
        ]);

        $signature = hash('sha256', $libraryId . 'test-api-key');

        $response = $this->postJson('/api/webhooks/bunny', [
            'Status' => 4,
            'VideoGuid' => $videoGuid,
            'VideoLibraryId' => $libraryId,
        ], [
            'Webhook-Signature' => $signature,
        ]);

        $response->assertOk();
        Queue::assertPushed(FetchBunnyVideoDurationJob::class, function (FetchBunnyVideoDurationJob $job) use ($lecture, $libraryId, $videoGuid) {
            return $job->lectureId === $lecture->id
                && $job->libraryId === $libraryId
                && $job->videoGuid === $videoGuid;
        });
    }
}
