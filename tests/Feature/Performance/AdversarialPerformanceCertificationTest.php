<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Jobs\FlushFeatureSectionAnalyticsJob;
use App\Jobs\SendOrderNotifications;
use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseLanguage;
use App\Models\Order;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\VideoProgress;
use App\Services\CertificateService;
use App\Services\ContentAccessService;
use App\Services\CourseProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdversarialPerformanceCertificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ContentAccessService::flushStaticCache();
        Cache::flush();
    }

    /**
     * Helper to seed standard course with chapters and lectures.
     */
    private function createFullCourse(int $chapterCount = 2, int $lecturePerChapter = 3): Course
    {
        $category = Category::firstOrCreate(['slug' => 'tech'], ['name' => 'Technology', 'status' => 1]);
        $language = CourseLanguage::firstOrCreate(['slug' => 'en'], ['name' => 'English', 'is_active' => 1]);
        $instructor = User::factory()->create();

        $course = Course::factory()->create([
            'category_id' => $category->id,
            'language_id' => $language->id,
            'user_id' => $instructor->id,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);

        for ($c = 1; $c <= $chapterCount; $c++) {
            $chapter = CourseChapter::create([
                'course_id' => $course->id,
                'user_id' => $instructor->id,
                'title' => "Chapter {$c}",
                'chapter_order' => $c,
                'is_active' => true,
            ]);

            for ($l = 1; $l <= $lecturePerChapter; $l++) {
                CourseChapterLecture::create([
                    'user_id' => $instructor->id,
                    'course_chapter_id' => $chapter->id,
                    'title' => "Lecture {$c}.{$l}",
                    'slug' => "lecture-{$course->id}-{$c}-{$l}-" . \Illuminate\Support\Str::random(5),
                    'lecture_order' => $l,
                    'type' => 'file',
                    'file' => "videos/c{$course->id}_ch{$c}_l{$l}.mp4",
                    'file_extension' => 'mp4',
                    'duration_seconds' => 600,
                    'is_active' => true,
                    'free_preview' => false,
                ]);
            }
        }

        return $course;
    }

    /** @test */
    public function test_perf_01_catalog_listing_n_plus_one_prevention(): void
    {
        // Seed 10 courses
        for ($i = 0; $i < 10; $i++) {
            $this->createFullCourse(1, 2);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/get-courses?per_page=10');
        $response->assertOk();

        $queries = DB::getQueryLog();
        $queryCount = count($queries);
        DB::disableQueryLog();

        // Query count must be bounded and small (< 15 queries for 10 courses with relations and aggregates)
        $this->assertLessThanOrEqual(15, $queryCount, "Course catalog executed {$queryCount} queries, indicating potential N+1.");
    }

    /** @test */
    public function test_perf_02_curriculum_breakdown_batch_eager_loading(): void
    {
        $course = $this->createFullCourse(4, 5); // 4 chapters, 20 lectures
        $user = User::factory()->create(['is_active' => true]);

        // Seed some video progress
        $lectures = CourseChapterLecture::whereIn('course_chapter_id', $course->chapters->pluck('id'))->get();
        foreach ($lectures->take(10) as $lecture) {
            VideoProgress::create([
                'user_id' => $user->id,
                'lecture_id' => $lecture->id,
                'watched_seconds' => 600,
                'total_seconds' => 600,
                'last_position' => 600,
                'watch_percentage' => 100.0,
                'is_completed' => true,
                'completed_at' => now(),
            ]);
        }

        $service = app(CourseProgressService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $detailed = $service->getDetailedProgress($user->id, $course->id);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertIsArray($detailed);
        $this->assertEquals(20, $detailed['summary']['total_items']);
        $this->assertEquals(10, $detailed['summary']['completed_items']);

        // Must load in batch (course + chapters.lectures + trackings + video_progress = ~4-6 queries max)
        $this->assertLessThanOrEqual(8, $queryCount, "Detailed progress executed {$queryCount} queries instead of batch loading.");
    }

    /** @test */
    public function test_perf_03_user_cache_isolation_and_cross_contamination_immunity(): void
    {
        $course = $this->createFullCourse(2, 2);
        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);

        // User A completes all 4 lectures
        $lectures = CourseChapterLecture::whereIn('course_chapter_id', $course->chapters->pluck('id'))->get();
        foreach ($lectures as $lecture) {
            VideoProgress::create([
                'user_id' => $userA->id,
                'lecture_id' => $lecture->id,
                'watched_seconds' => 600,
                'total_seconds' => 600,
                'last_position' => 600,
                'watch_percentage' => 100.0,
                'is_completed' => true,
                'completed_at' => now(),
            ]);
        }

        $service = app(CourseProgressService::class);

        // Calculate User A progress (stores in user:A:course:X:progress cache)
        $progressA = $service->calculateAndUpdateProgress($userA->id, $course->id);
        $this->assertEquals(100.0, (float) $progressA->progress_percentage);
        $this->assertEquals('completed', $progressA->status);

        // Fetch User B progress with cache
        $progressB = $service->getProgressWithCache($userB->id, $course->id);
        $this->assertEquals(0.0, (float) $progressB->progress_percentage);
        $this->assertNotEquals('completed', $progressB->status);

        // Verify cache keys are strictly distinct
        $cachedA = Cache::get("user:{$userA->id}:course:{$course->id}:progress");
        $cachedB = Cache::get("user:{$userB->id}:course:{$course->id}:progress");

        $this->assertNotNull($cachedA);
        $this->assertNotNull($cachedB);
        $this->assertEquals(100.0, (float) $cachedA->progress_percentage);
        $this->assertEquals(0.0, (float) $cachedB->progress_percentage);
    }

    /** @test */
    public function test_perf_04_cache_invalidation_on_progress_update(): void
    {
        $course = $this->createFullCourse(1, 2);
        $user = User::factory()->create(['is_active' => true]);
        $service = app(CourseProgressService::class);

        // Initial progress: 0%
        $progress1 = $service->calculateAndUpdateProgress($user->id, $course->id);
        $this->assertEquals(0.0, (float) $progress1->progress_percentage);

        // Watch 1 lecture
        $lecture = $course->chapters->first()->lectures->first();
        VideoProgress::create([
            'user_id' => $user->id,
            'lecture_id' => $lecture->id,
            'watched_seconds' => 600,
            'total_seconds' => 600,
            'last_position' => 600,
            'watch_percentage' => 100.0,
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        // Recalculate and verify cache was refreshed
        $progress2 = $service->calculateAndUpdateProgress($user->id, $course->id);
        $this->assertEquals(50.0, (float) $progress2->progress_percentage);

        $cached = Cache::get("user:{$user->id}:course:{$course->id}:progress");
        $this->assertEquals(50.0, (float) $cached->progress_percentage);
    }

    /** @test */
    public function test_perf_05_pagination_maximum_limit_enforcement(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createFullCourse(1, 1);
        }

        // Request 500 items per page - must be rejected by validator with 422 to prevent DoS / memory exhaustion
        $oversizedResponse = $this->getJson('/api/get-courses?per_page=500');
        $oversizedResponse->assertUnprocessable();

        // Valid capped request (per_page=100) must succeed
        $validResponse = $this->getJson('/api/get-courses?per_page=100');
        $validResponse->assertOk();
        $data = $validResponse->json();
        $perPage = $data['data']['courses']['per_page'] ?? $data['per_page'] ?? count($data['data']['courses']['data'] ?? []);
        $this->assertLessThanOrEqual(100, $perPage);
    }

    /** @test */
    public function test_perf_06_order_notification_job_handles_null_instructors_gracefully(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'SK-PERF-' . rand(1000, 9999),
            'payment_method' => 'card',
            'total_price' => 100.00,
            'final_price' => 100.00,
            'status' => 'completed',
        ]);

        $job = new SendOrderNotifications($order, $user);
        
        // Execute handle() directly - must complete without throwing any exception
        $job->handle();
        $this->assertTrue(true);
    }

    /** @test */
    public function test_perf_07_distributed_certificate_lock_concurrency_race_safety(): void
    {
        $course = $this->createFullCourse(1, 2);
        $user = User::factory()->create(['is_active' => true, 'name' => 'Perf Student']);

        // Authoritatively complete all lectures with 100% video progress
        $lectures = CourseChapterLecture::whereIn('course_chapter_id', $course->chapters->pluck('id'))->get();
        foreach ($lectures as $lecture) {
            VideoProgress::create([
                'user_id' => $user->id,
                'lecture_id' => $lecture->id,
                'watched_seconds' => 600,
                'total_seconds' => 600,
                'last_position' => 600,
                'watch_percentage' => 100.0,
                'is_completed' => true,
                'completed_at' => now(),
            ]);
        }

        $service = app(CertificateService::class);

        // Issue certificate twice sequentially (simulating concurrent race resolution)
        $cert1 = $service->issueCertificate($user->id, $course->id, ['issuance_source' => 'admin_manual']);
        $cert2 = $service->issueCertificate($user->id, $course->id, ['issuance_source' => 'admin_manual']);

        $this->assertNotNull($cert1);
        $this->assertNotNull($cert2);
        $this->assertEquals($cert1->id, $cert2->id);
        $this->assertEquals($cert1->certificate_number, $cert2->certificate_number);

        // Exactly 1 certificate in database
        $count = DB::table('course_certificates')->where('user_id', $user->id)->where('course_id', $course->id)->count();
        $this->assertEquals(1, $count);
    }

    /** @test */
    public function test_perf_08_database_transaction_safety_and_connection_cleanup(): void
    {
        $initialLevel = DB::transactionLevel();

        try {
            DB::transaction(function () {
                User::factory()->create(['email' => 'transact@example.com']);
                throw new \RuntimeException('Simulated failure');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Transaction level must return to initial level
        $this->assertEquals($initialLevel, DB::transactionLevel());
        $this->assertDatabaseMissing('users', ['email' => 'transact@example.com']);
    }
}
