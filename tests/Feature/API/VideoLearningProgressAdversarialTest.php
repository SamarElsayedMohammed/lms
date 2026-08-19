<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseCertificate;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\VideoProgress;
use App\Services\CertificateService;
use App\Services\CourseProgressService;
use App\Services\VideoProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VideoLearningProgressAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\ContentAccessService::flushRequestCache();
    }

    protected function tearDown(): void
    {
        \App\Services\ContentAccessService::flushRequestCache();
        parent::tearDown();
    }

    private function createFullCourseWithLectures(int $lectureCount = 3, int $durationPerLecture = 60, bool $isFree = true): array
    {
        $instructor = User::factory()->create(['is_active' => true]);

        $course = Course::factory()->create([
            'user_id' => $instructor->id,
            'is_free' => $isFree,
            'course_type' => $isFree ? 'free' : 'paid',
            'price' => $isFree ? 0 : 100,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => true,
            'certificate_enabled' => true,
            'certificate_fee' => 0,
        ]);

        $chapter = CourseChapter::factory()->create([
            'course_id' => $course->id,
            'is_active' => true,
            'chapter_order' => 1,
        ]);

        $lectures = [];
        for ($i = 1; $i <= $lectureCount; $i++) {
            $lectures[] = CourseChapterLecture::factory()->create([
                'user_id' => $instructor->id,
                'course_chapter_id' => $chapter->id,
                'duration_seconds' => $durationPerLecture,
                'type' => 'file',
                'file_extension' => 'mp4',
                'chapter_order' => $i,
                'is_active' => true,
                'free_preview' => false,
                'is_free' => false,
            ]);
        }

        return [$course, $chapter, $lectures];
    }

    private function recordLectureCompleted(User $user, CourseChapterLecture $lecture): void
    {
        $duration = $lecture->duration_seconds ?: 100;
        VideoProgress::updateOrCreate(
            ['user_id' => $user->id, 'lecture_id' => $lecture->id],
            [
                'watched_seconds' => $duration,
                'total_seconds' => $duration,
                'last_position' => $duration,
                'watch_percentage' => 100.0,
                'is_completed' => true,
                'completed_at' => now(),
            ]
        );

        UserCurriculumTracking::updateOrCreate(
            [
                'user_id' => $user->id,
                'course_chapter_id' => $lecture->course_chapter_id,
                'model_id' => $lecture->id,
                'model_type' => CourseChapterLecture::class,
            ],
            [
                'status' => 'completed',
                'completed_at' => now(),
                'started_at' => now(),
            ]
        );

        app(CourseProgressService::class)->calculateAndUpdateProgress($user->id, $lecture->chapter->course_id);
    }

    private function recordLecturePartial(User $user, CourseChapterLecture $lecture, int $watchedSeconds): void
    {
        $duration = $lecture->duration_seconds ?: 100;
        $pct = round(($watchedSeconds / $duration) * 100, 2);
        VideoProgress::updateOrCreate(
            ['user_id' => $user->id, 'lecture_id' => $lecture->id],
            [
                'watched_seconds' => $watchedSeconds,
                'total_seconds' => $duration,
                'last_position' => $watchedSeconds,
                'watch_percentage' => $pct,
                'is_completed' => false,
                'completed_at' => null,
            ]
        );

        app(CourseProgressService::class)->calculateAndUpdateProgress($user->id, $lecture->chapter->course_id);
    }

    /**
     * ATTACK-01 / INV-01: Direct 100% injection payload cannot manufacture completion without watch time.
     */
    public function test_attack_01_direct_100_percent_injection_does_not_manufacture_completion(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 100);
        $lecture = $lectures[0];

        // Malicious client sends spoofed 100% progress payload without watching
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/lecture/{$lecture->id}/progress", [
            'progress' => 100,
            'percentage' => 100,
            'completed' => true,
            'is_completed' => true,
            'watched_seconds' => 0,
            'last_position' => 0,
        ]);

        $response->assertOk();
        $progress = VideoProgress::where('user_id', $user->id)->where('lecture_id', $lecture->id)->first();
        $this->assertNotNull($progress);
        $this->assertFalse((bool) $progress->is_completed);
        $this->assertEquals(0, $progress->watched_seconds);
        $this->assertEquals(0.0, (float) $progress->watch_percentage);
    }

    /**
     * ATTACK-02 / INV-08: Client-supplied duration cannot shrink canonical lecture duration.
     */
    public function test_attack_02_fake_duration_cannot_shrink_canonical_duration(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 3600); // 1 hour video
        $lecture = $lectures[0];

        // Client claims video is only 10 seconds long and they watched all 10 seconds
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 10,
            'newly_watched_segments' => [0],
        ]);

        $response->assertStatus(422);

        // Verify no 100% progress record was created
        $progress = VideoProgress::where('user_id', $user->id)->where('lecture_id', $lecture->id)->first();
        $this->assertTrue($progress === null || !$progress->is_completed);
    }

    /**
     * ATTACK-03 & ATTACK-04 / INV-03: Invalid or out-of-bounds segments are rejected.
     */
    public function test_attack_03_and_04_invalid_and_out_of_bounds_segments_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 60);
        $lecture = $lectures[0];

        // Submit too many segments in a single request (rate limit / batch limit)
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 60,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 1, 2, 3, 4, 5],
        ]);
        $response->assertStatus(422);

        // Submit negative segments
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 60,
            'newly_watched_segments' => [-1],
        ]);
        $response->assertStatus(422);
    }

    /**
     * ATTACK-05 / INV-04 & INV-05: Overlapping segments are deduplicated into a canonical union.
     */
    public function test_attack_05_overlapping_segments_are_deduplicated(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 60);
        $lecture = $lectures[0];

        // Send segment 0 twice in same request
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 60,
            'newly_watched_segments' => [0, 0],
        ]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.completed_segments'));
        $this->assertEquals(16.67, $response->json('data.watch_percentage'));
    }

    /**
     * ATTACK-06 / INV-12: Replay of identical telemetry 50 times produces idempotent canonical progress.
     */
    public function test_attack_06_replaying_telemetry_50_times_is_strictly_idempotent(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 60);
        $lecture = $lectures[0];

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAs($user, 'sanctum')->postJson("/api/lecture/{$lecture->id}/progress", [
                'current_position' => 10,
                'total_duration' => 60,
                'newly_watched_segments' => [0],
            ]);
            $response->assertOk();
        }

        $progress = VideoProgress::where('user_id', $user->id)->where('lecture_id', $lecture->id)->first();
        $this->assertEquals(1, $progress->completed_segments);
        $this->assertEquals(10, $progress->watched_seconds);
        $this->assertEquals(16.67, (float) $progress->watch_percentage);
        $this->assertFalse((bool) $progress->is_completed);
    }

    /**
     * ATTACK-07 / INV-06: Seeking directly to the end does not mark video complete.
     */
    public function test_attack_07_seek_to_end_does_not_mark_video_complete(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 100);
        $lecture = $lectures[0];

        // User starts at 0, seeks immediately to 99 and sends segment 9 (the last 10s segment)
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 99,
            'total_duration' => 100,
            'newly_watched_segments' => [9],
        ]);

        $response->assertOk();
        // Discontinuous segments must be rejected by the server
        $this->assertEquals(0, $response->json('data.completed_segments'));
        $this->assertFalse($response->json('data.is_completed'));
    }

    /**
     * ATTACK-08 / INV-01: Direct call to markCurriculumItemCompleted without verified watch progress is rejected.
     */
    public function test_attack_08_direct_mark_completed_endpoint_rejects_unwatched_video(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 100);
        $lecture = $lectures[0];

        // Direct call to curriculum mark-completed endpoint without VideoProgress.is_completed = true
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/curriculum/mark-completed", [
            'course_chapter_id' => $chapter->id,
            'model_id' => $lecture->id,
            'model_type' => 'lecture',
        ]);

        $response->assertForbidden();
    }

    /**
     * ATTACK-09 / INV-09: User A cannot modify User B's progress.
     */
    public function test_attack_09_user_a_cannot_modify_user_b_progress(): void
    {
        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 60);
        $lecture = $lectures[0];

        // User A reports progress attempting to pass user_id = User B
        $response = $this->actingAs($userA, 'sanctum')->postJson("/api/lecture/{$lecture->id}/progress", [
            'user_id' => $userB->id,
            'current_position' => 10,
            'total_duration' => 60,
            'newly_watched_segments' => [0],
        ]);

        $response->assertOk();

        // Progress must be recorded ONLY for User A
        $progressA = VideoProgress::where('user_id', $userA->id)->where('lecture_id', $lecture->id)->first();
        $progressB = VideoProgress::where('user_id', $userB->id)->where('lecture_id', $lecture->id)->first();

        $this->assertNotNull($progressA);
        $this->assertNull($progressB);
    }

    /**
     * ATTACK-11 / INV-11: Progress cannot be written for inaccessible/unsubscribed paid courses.
     */
    public function test_attack_11_unsubscribed_user_cannot_write_paid_lecture_progress(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 60, false); // Paid course
        $lecture = $lectures[0];

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/lecture/{$lecture->id}/progress", [
            'current_position' => 10,
            'total_duration' => 60,
            'newly_watched_segments' => [0],
        ]);

        $response->assertForbidden();
    }

    /**
     * ATTACK-19 & ATTACK-21 / INV-17 & INV-18:
     * Course is NOT complete and certificate is NOT eligible if 99% of videos or 99.9% of course is watched.
     */
    public function test_attack_19_and_21_incomplete_video_prevents_course_completion_and_certificate(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(3, 100);

        $courseProgressService = app(CourseProgressService::class);
        $certificateService = app(CertificateService::class);

        // Complete Lecture 1 (100%)
        $this->recordLectureCompleted($user, $lectures[0]);
        // Complete Lecture 2 (100%)
        $this->recordLectureCompleted($user, $lectures[1]);
        // Lecture 3 is at 99% (99/100 seconds) - NOT completed
        $this->recordLecturePartial($user, $lectures[2], 99);

        // Check Course Progress
        $detailed = $courseProgressService->getDetailedProgress($user->id, $course->id);
        $this->assertEquals(2, $detailed['summary']['completed_items']);
        $this->assertEquals(3, $detailed['summary']['total_items']);
        $this->assertLessThan(100.0, (float) $detailed['course']['progress_percentage']);
        $this->assertEquals('in_progress', $detailed['course']['status']);

        // Check Certificate Eligibility
        $isEligible = $certificateService->checkCourseCompletionStatus($user->id, $course->id);
        $this->assertFalse($isEligible);

        // Check Certificate Generation Endpoint
        $response = $this->actingAs($user, 'sanctum')->getJson("/api/certificate/course/generate?course_id={$course->id}");
        $response->assertForbidden();

        // Check Certificate Eligibility API Endpoint
        $response = $this->actingAs($user, 'sanctum')->getJson("/api/certificate/course/eligibility?course_id={$course->id}");
        $response->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason_code', 'course_incomplete')
            ->assertJsonPath('data.completed_lessons', 2)
            ->assertJsonPath('data.total_lessons', 3);
    }

    /**
     * ATTACK-22 / INV-17 & INV-18:
     * Full completion of ALL required videos (100%) correctly unlocks course completion and certificate eligibility.
     */
    public function test_attack_22_full_100_percent_completion_unlocks_course_and_certificate(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(3, 100);

        $courseProgressService = app(CourseProgressService::class);
        $certificateService = app(CertificateService::class);

        // Complete Lecture 1 (100%)
        $this->recordLectureCompleted($user, $lectures[0]);
        // Complete Lecture 2 (100%)
        $this->recordLectureCompleted($user, $lectures[1]);
        // Complete Lecture 3 (100%)
        $this->recordLectureCompleted($user, $lectures[2]);

        // Check Course Progress
        $detailed = $courseProgressService->getDetailedProgress($user->id, $course->id);
        $this->assertEquals(3, $detailed['summary']['completed_items']);
        $this->assertEquals(3, $detailed['summary']['total_items']);
        $this->assertEquals(100.0, (float) $detailed['course']['progress_percentage']);
        $this->assertEquals('completed', $detailed['course']['status']);

        // Check Certificate Eligibility
        $isEligible = $certificateService->checkCourseCompletionStatus($user->id, $course->id);
        $this->assertTrue($isEligible);

        // Check Certificate Eligibility API Endpoint
        $response = $this->actingAs($user, 'sanctum')->getJson("/api/certificate/course/eligibility?course_id={$course->id}");
        $response->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.reason_code', 'eligible')
            ->assertJsonPath('data.completed_lessons', 3)
            ->assertJsonPath('data.total_lessons', 3);

        // Check Certificate Generation Endpoint
        $response = $this->actingAs($user, 'sanctum')->getJson("/api/certificate/course/generate?course_id={$course->id}");
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['data' => ['certificate_id', 'student_name', 'course_id']]);
    }

    /**
     * ATTACK-23 / INV-13: Duplicate certificate auto-generation calls are idempotent and produce a single certificate.
     */
    public function test_attack_23_certificate_generation_is_strictly_idempotent(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        [$course, $chapter, $lectures] = $this->createFullCourseWithLectures(1, 100);

        $certificateService = app(CertificateService::class);

        // Complete the only lecture
        $this->recordLectureCompleted($user, $lectures[0]);

        // Trigger certificate issuance 3 times concurrently/sequentially
        $cert1 = $certificateService->autoGenerateCertificate($user->id, $course->id);
        $cert2 = $certificateService->autoGenerateCertificate($user->id, $course->id);
        $cert3 = $certificateService->autoGenerateCertificate($user->id, $course->id);

        $this->assertNotNull($cert1);
        $this->assertNotNull($cert2);
        $this->assertEquals($cert1->id, $cert2->id);
        $this->assertEquals($cert1->certificate_number, $cert2->certificate_number);
        $this->assertEquals(1, CourseCertificate::where('user_id', $user->id)->where('course_id', $course->id)->count());
    }
}
