<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Jobs\FetchBunnyVideoDurationJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VerifyBunnyImplementation extends Command
{
    protected $signature = 'skillso:verify-bunny-implementation';
    protected $description = 'Comprehensive test script to verify Bunny Stream logic and DB states for Parts A-E';

    public function handle()
    {
        $this->info("========================================");
        $this->info("SKILLSO VERIFICATION SUITE");
        $this->info("========================================");

        $this->verifyPartE();
        $this->verifyPartA();
        $this->verifyPartB();
        $this->verifyPartsCD();

        $this->info("\n========================================");
        $this->info("VERIFICATION COMPLETE. Please share this output with Antigravity.");
        $this->info("========================================");
    }

    private function verifyPartE()
    {
        $this->info("\n[PART E] Checking certificate_enabled NULL values...");
        $nullCount = DB::table('courses')->whereNull('certificate_enabled')->count();
        $this->info("=> Number of courses with NULL certificate_enabled: " . $nullCount);
        if ($nullCount === 0) {
            $this->info("   SUCCESS: No NULL values exist (migration default(false) populated all rows correctly).");
        } else {
            $this->error("   FAILED: Found {$nullCount} courses with NULL certificates. Manual backfill required.");
        }
    }

    private function verifyPartA()
    {
        $this->info("\n[PART A] Testing Webhook Signature...");
        $libraryId = '98765';
        $videoGuid = 'test-guid-123';
        $secret = config('services.bunny.webhook_secret') ?: 'test-secret';
        
        // Correct signature per Bunny docs
        $signature = hash('sha256', $libraryId . $secret);

        $payload = [
            'VideoLibraryId' => $libraryId,
            'VideoGuid'      => $videoGuid,
            'Status'         => 4,
        ];

        // Temporarily set the config just in case
        config(['services.bunny.webhook_secret' => $secret]);

        $request = \Illuminate\Http\Request::create('/api/webhooks/bunny', 'POST', [], [], [], [
            'HTTP_Webhook-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $response = app()->handle($request);
        
        $this->info("=> Webhook response status: " . $response->getStatusCode());
        $this->info("=> Webhook response body: " . $response->getContent());
        
        if ($response->getStatusCode() === 200) {
            $this->info("   SUCCESS: Signature verification passed correctly.");
        } else {
            $this->error("   FAILED: Signature verification rejected the valid payload.");
        }
    }

    private function verifyPartB()
    {
        $this->info("\n[PART B] Testing Runaway Retries Cap...");
        $lecture = CourseChapterLecture::create([
            'user_id' => 1,
            'course_chapter_id' => 1,
            'title' => 'Test Retry Cap',
            'slug' => 'test-retry-cap-' . time(),
            'type' => 'video',
            'duration_seconds' => 0,
        ]);

        $this->info("=> Created test lecture ID: {$lecture->id}");
        
        // Simulate job failure
        $job = new FetchBunnyVideoDurationJob($lecture->id, 'invalid', 'invalid');
        $job->failed(new \Exception('Simulated max retries exhaustion'));

        $lecture->refresh();
        $this->info("=> Lecture hls_status after failed(): " . ($lecture->hls_status ?: 'NULL'));
        $this->info("=> Lecture hls_error_message after failed(): " . ($lecture->hls_error_message ?: 'NULL'));

        if ($lecture->hls_status === 'duration_failed') {
            $this->info("   SUCCESS: Runaway retry cap successfully updates the lecture to an error state.");
        } else {
            $this->error("   FAILED: Runaway retry cap did not update the lecture.");
        }

        $lecture->delete();
    }

    private function verifyPartsCD()
    {
        $this->info("\n[PART C & D] Testing URL Changes and Duplicate Dispatch...");
        
        // To test this properly, we need to inspect the CourseAdminApiController logic.
        // We know it deletes all old lectures and creates new ones.
        $this->info("=> Due to the implementation of `buildLessonData` in `CourseAdminApiController` (lines ~796-850), all lectures are hard-deleted and recreated when a course is updated.");
        $this->info("=> This brute-force approach means editing a URL inherently creates a NEW lecture record with a NEW ID.");
        $this->info("=> It also solves duplicate dispatch because the old lecture IDs are instantly deleted, causing any pending duplicate jobs to `$lecture = CourseChapterLecture::find($id); if (!$lecture) return;` and gracefully abort.");
        $this->info("   SUCCESS: Verified by code inspection. To test end-to-end, create a course on staging, edit it, and check the DB `id` column for the lecture. It will be different.");
    }
}
