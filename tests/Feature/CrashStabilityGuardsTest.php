<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\BackfillBunnyDurations;
use App\Jobs\DispatchNotificationCampaignJob;
use App\Jobs\FetchBunnyVideoDurationJob;
use App\Jobs\SendNotificationCampaignChunkJob;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrashStabilityGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_instructor_students_are_paginated_before_progress_mapping(): void
    {
        $source = $this->source('app/Http/Controllers/API/Concerns/ServesInstructorCourseOps.php');
        $method = $this->methodBody($source, 'getEnrolledStudentsWithProgress', 'getCourseAssignmentDetails');

        $this->assertStringContainsString('->paginate(', $method);
        $this->assertStringNotContainsString('->slice(', $method);
    }

    public function test_chatbot_outbound_calls_have_bounded_deadlines(): void
    {
        $source = $this->source('app/Services/ChatBotService.php');

        $this->assertStringContainsString('OVERALL_DEADLINE_SECONDS', $source);
        $this->assertStringContainsString('connectTimeout(', $source);
        $this->assertStringContainsString('remainingSeconds', $source);
    }

    public function test_uploaded_video_duration_analysis_is_queued(): void
    {
        $source = $this->source('app/Services/HelperService.php');
        $method = $this->methodBody($source, 'updateAndGetLectureData', 'updateAndGetDocumentData');

        $this->assertStringNotContainsString('new \\getID3()', $method);
        $this->assertStringContainsString('AnalyzeLectureDurationJob::dispatch', $method);
    }

    public function test_notification_campaign_fans_out_recipient_chunks(): void
    {
        $source = $this->source('app/Jobs/DispatchNotificationCampaignJob.php');

        $this->assertStringContainsString('SendNotificationCampaignChunkJob::dispatch', $source);
        $this->assertStringNotContainsString('Notification::send(', $source);
    }

    public function test_full_table_maintenance_uses_bounded_iteration(): void
    {
        foreach ([
            'app/Console/Commands/RecalculateProgressCommand.php',
            'app/Services/AffiliateService.php',
            'app/Services/SubscriptionService.php',
        ] as $path) {
            $this->assertStringContainsString('chunkById(', $this->source($path), $path);
        }
    }

    public function test_bunny_commands_bound_dispatch_and_http_waits(): void
    {
        $backfill = $this->source('app/Console/Commands/BackfillBunnyDurations.php');
        $webhook = $this->source('app/Console/Commands/TestBunnyWebhook.php');

        $this->assertStringContainsString('--limit=', $backfill);
        $this->assertStringContainsString('chunkById(', $backfill);
        $this->assertStringContainsString('connectTimeout(', $webhook);
        $this->assertStringContainsString('timeout(', $webhook);
    }

    public function test_notification_campaign_dispatches_one_job_per_recipient_chunk(): void
    {
        Queue::fake([SendNotificationCampaignChunkJob::class]);
        User::factory()->count(205)->create();

        (new DispatchNotificationCampaignJob(
            41,
            ['target_type' => 'all'],
            ['title' => 'اختبار', 'message' => 'رسالة'],
            ['database'],
        ))->handle();

        Queue::assertPushed(SendNotificationCampaignChunkJob::class, 3);
        Queue::assertPushed(
            SendNotificationCampaignChunkJob::class,
            fn (SendNotificationCampaignChunkJob $job): bool => count($job->userIds) <= 100,
        );
    }

    public function test_bunny_backfill_honors_per_run_dispatch_limit(): void
    {
        Queue::fake([FetchBunnyVideoDurationJob::class]);
        $course = Course::factory()->create();
        $chapter = CourseChapter::factory()->create(['course_id' => $course->id]);

        foreach (range(1, 3) as $index) {
            CourseChapterLecture::factory()->create([
                'course_chapter_id' => $chapter->id,
                'type' => 'youtube_url',
                'youtube_url' => "https://iframe.mediadelivery.net/embed/123456/video-{$index}",
                'duration_seconds' => 0,
            ]);
        }

        $this->artisan(BackfillBunnyDurations::class, ['--limit' => 2, '--chunk' => 1])
            ->assertSuccessful();

        Queue::assertPushed(FetchBunnyVideoDurationJob::class, 2);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertNotFalse($source, $path);

        return $source;
    }

    private function methodBody(string $source, string $method, string $nextMethod): string
    {
        $start = strpos($source, "function {$method}");
        $end = strpos($source, "function {$nextMethod}", $start ?: 0);

        $this->assertNotFalse($start, $method);
        $this->assertNotFalse($end, $nextMethod);

        return substr($source, $start, $end - $start);
    }
}
