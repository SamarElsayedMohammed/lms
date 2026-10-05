<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\LessonProgress;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonProgressHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $unenrolledStudent;
    private User $secondStudent;
    private Course $course;
    private CourseChapter $chapter;
    private CourseChapterLecture $shortLesson;
    private CourseChapterLecture $standardLesson;
    private CourseChapterLecture $lockedLesson;

    protected function setUp(): void
    {
        parent::setUp();

        $instructor = User::factory()->create(['is_active' => true]);
        $this->student = User::factory()->create(['is_active' => true]);
        $this->unenrolledStudent = User::factory()->create(['is_active' => true]);
        $this->secondStudent = User::factory()->create(['is_active' => true]);

        $this->course = Course::factory()->create([
            'user_id'             => $instructor->id,
            'title'               => 'Hardened Progress Course',
            'certificate_enabled' => true,
            'sequential_access'   => true,
            'is_active'           => true,
            'status'              => 'publish',
            'approval_status'     => 'approved',
        ]);

        $this->chapter = CourseChapter::create([
            'course_id'     => $this->course->id,
            'user_id'       => $instructor->id,
            'title'         => 'Hardening Chapter',
            'chapter_order' => 1,
            'is_active'     => true,
        ]);

        // Lesson 1: Short 47-second video (Bunny Stream reproduction case)
        $this->shortLesson = CourseChapterLecture::create([
            'course_chapter_id' => $this->chapter->id,
            'user_id'           => $instructor->id,
            'title'             => 'Short 47s Intro Lesson',
            'slug'              => 'short-47s-lesson',
            'chapter_order'     => 1,
            'duration_seconds'  => 47,
            'is_active'         => true,
            'type'              => 'url',
        ]);

        // Lesson 2: Standard 120-second video
        $this->standardLesson = CourseChapterLecture::create([
            'course_chapter_id' => $this->chapter->id,
            'user_id'           => $instructor->id,
            'title'             => 'Standard 120s Lesson',
            'slug'              => 'standard-120s-lesson',
            'chapter_order'     => 2,
            'duration_seconds'  => 120,
            'is_active'         => true,
            'type'              => 'url',
        ]);

        // Lesson 3: 180-second lesson
        $this->lockedLesson = CourseChapterLecture::create([
            'course_chapter_id' => $this->chapter->id,
            'user_id'           => $instructor->id,
            'title'             => 'Locked 180s Lesson',
            'slug'              => 'locked-180s-lesson',
            'chapter_order'     => 3,
            'duration_seconds'  => 180,
            'is_active'         => true,
            'type'              => 'url',
        ]);

        // Enroll primary student
        \App\Models\Course\UserCourseTrack::create([
            'user_id'   => $this->student->id,
            'course_id' => $this->course->id,
            'status'    => 'in_progress',
        ]);

        $order = Order::create([
            'user_id'        => $this->student->id,
            'status'         => 'completed',
            'payment_method' => 'wallet',
            'total_price'    => 100,
            'final_price'    => 100,
            'order_number'   => 'ORD-HARDEN-1-' . microtime(true),
        ]);
        OrderCourse::create([
            'order_id'  => $order->id,
            'course_id' => $this->course->id,
            'price'     => 100,
            'tax_price' => 0,
        ]);

        // Enroll second student
        \App\Models\Course\UserCourseTrack::create([
            'user_id'   => $this->secondStudent->id,
            'course_id' => $this->course->id,
            'status'    => 'in_progress',
        ]);

        $order2 = Order::create([
            'user_id'        => $this->secondStudent->id,
            'status'         => 'completed',
            'payment_method' => 'wallet',
            'total_price'    => 100,
            'final_price'    => 100,
            'order_number'   => 'ORD-HARDEN-2-' . microtime(true),
        ]);
        OrderCourse::create([
            'order_id'  => $order2->id,
            'course_id' => $this->course->id,
            'price'     => 100,
            'tax_price' => 0,
        ]);
    }

    /**
     * 1. Short 47s video must complete when 'ended' event is received and unlock lesson 2.
     */
    public function test_short_47s_video_completes_on_ended_event_and_unlocks_lesson_2(): void
    {
        // First, confirm lesson 2 is locked
        $initCheck = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$this->standardLesson->id}/progress");
        $initCheck->assertStatus(403)->assertJson(['code' => 'LESSON_LOCKED']);

        // Student legitimately watches 47-second video through heartbeats
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00'));
        $this->actingAs($this->student)->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
            'current_time' => 15,
            'duration'     => 47,
            'event'        => 'progress',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:10'));
        $this->actingAs($this->student)->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
            'current_time' => 30,
            'duration'     => 47,
            'event'        => 'progress',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:20'));
        $this->actingAs($this->student)->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
            'current_time' => 45,
            'duration'     => 47,
            'event'        => 'progress',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:22'));
        $response = $this->actingAs($this->student)->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
            'current_time' => 47,
            'duration'     => 47,
            'event'        => 'ended',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'lesson_id'    => $this->shortLesson->id,
                    'is_completed' => true,
                    'percent'      => 100,
                ],
            ]);

        $this->assertDatabaseHas('lesson_progress', [
            'user_id'      => $this->student->id,
            'lesson_id'    => $this->shortLesson->id,
            'is_completed' => true,
        ]);

        // Lesson 2 must now be unlocked
        $unlockedCheck = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$this->standardLesson->id}/progress");
        $unlockedCheck->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'lesson_id'    => $this->standardLesson->id,
                    'is_completed' => false,
                ],
            ]);

        Carbon::setTestNow();
    }

    /**
     * 2. Anti-cheat accommodates 2.0x playback speed viewers without truncating watched time.
     */
    public function test_playback_speed_2x_is_accepted_by_anti_cheat(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00'));

        // First heartbeat: user starts at 10s
        $hb1 = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 10,
                'duration'     => 47,
                'event'        => 'progress',
            ]);
        $hb1->assertStatus(200);

        // 10 seconds of real-world wall clock passes
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:10'));

        // At 2x speed, user advances 20s in the video (currentTime moves from 10 to 30)
        $hb2 = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 30,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        $hb2->assertStatus(200);
        $data2 = $hb2->json('data');

        // Must accept full 30 seconds since (2.2 * 10) + 6s tolerance = 28s jump allowed
        $this->assertEquals(30, $data2['last_position_seconds']);
        $this->assertEquals(30, $data2['watched_seconds']);

        Carbon::setTestNow(); // reset
    }

    /**
     * 3. Resuming from last position does not inflate watched_seconds or get flagged as cheat.
     */
    public function test_resume_from_last_position_does_not_inflate_watched_seconds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00'));

        // User watched up to 20s
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 20,
                'duration'     => 47,
                'event'        => 'pause',
            ]);

        // User reloads page 5 minutes later and resumes playback around 20s
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:05:00'));

        $resumeHb = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 20,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        $resumeHb->assertStatus(200);
        $data = $resumeHb->json('data');

        $this->assertEquals(20, $data['last_position_seconds']);
        $this->assertEquals(20, $data['watched_seconds']);

        // Now user watches 4 seconds normally
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:05:04'));

        $hbNext = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 24,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        $hbNext->assertStatus(200);
        $dataNext = $hbNext->json('data');

        $this->assertEquals(24, $dataNext['last_position_seconds']);
        $this->assertEquals(24, $dataNext['watched_seconds']);

        Carbon::setTestNow(); // reset
    }

    /**
     * 4. Unenrolled users receive 403 Forbidden on progress endpoints.
     */
    public function test_unenrolled_student_receives_403_on_progress_endpoints(): void
    {
        $getResponse = $this->actingAs($this->unenrolledStudent)
            ->getJson("/api/lessons/{$this->shortLesson->id}/progress");
        $getResponse->assertStatus(403);

        $postResponse = $this->actingAs($this->unenrolledStudent)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 10,
                'duration'     => 47,
                'event'        => 'progress',
            ]);
        $postResponse->assertStatus(403);
    }

    /**
     * 5. Student progress is strictly isolated across users.
     */
    public function test_progress_is_strictly_isolated_between_different_students(): void
    {
        // Student 1 watches 35s
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 35,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        // Student 2 has not watched yet
        $s2Progress = $this->actingAs($this->secondStudent)
            ->getJson("/api/lessons/{$this->shortLesson->id}/progress");

        $s2Progress->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'last_position_seconds' => 0,
                    'is_completed'          => false,
                    'percent'               => 0,
                ],
            ]);

        // Student 2 posts 10s
        $this->actingAs($this->secondStudent)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 10,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        // Student 1's progress remains at 35s
        $s1Check = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$this->shortLesson->id}/progress");
        $s1Check->assertStatus(200)
            ->assertJson([
                'data' => [
                    'last_position_seconds' => 35,
                ],
            ]);
    }

    /**
     * 6. Negative or invalid payload parameters return 422 Unprocessable Entity.
     */
    public function test_invalid_payload_returns_422(): void
    {
        // Negative current_time
        $res1 = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => -10,
                'duration'     => 47,
                'event'        => 'progress',
            ]);
        $res1->assertStatus(422);

        // Negative duration
        $res2 = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 10,
                'duration'     => -47,
                'event'        => 'progress',
            ]);
        $res2->assertStatus(422);

        // Invalid event enum
        $res3 = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 10,
                'duration'     => 47,
                'event'        => 'hacked_event',
            ]);
        $res3->assertStatus(422);
    }

    /**
     * 7. Out-of-order or reverse heartbeats preserve monotonic progress.
     */
    public function test_out_of_order_heartbeats_preserve_monotonic_progress(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00'));

        // Step 1: Watch 20s
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 20,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        // Step 2: 10s of wall clock passes, user reaches 40s (allowed jump: (10 * 2.2) + 6 = 28s)
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:10'));

        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 40,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        // Step 3: Stale or replay packet arriving with earlier position 15s
        $staleHb = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 15,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        $staleHb->assertStatus(200);
        $data = $staleHb->json('data');

        // last_position_seconds reflects current position (15) for resume point,
        // but max_position_seconds and watched_seconds never drop below 40.
        $this->assertEquals(15, $data['last_position_seconds']);
        $this->assertGreaterThanOrEqual(40, $data['max_position_seconds']);
        $this->assertGreaterThanOrEqual(40, $data['watched_seconds']);

        Carbon::setTestNow();
    }

    /**
     * 8. Total watch time aggregation aggregates accurately across lessons.
     */
    public function test_watched_seconds_aggregates_across_lessons(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00'));

        // Watch 20s in Lesson 1
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 20,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        // Complete Lesson 1
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:15'));
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 47,
                'duration'     => 47,
                'event'        => 'ended',
            ]);

        // Watch 30s in Lesson 2
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:10:00'));
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->standardLesson->id}/progress", [
                'current_time' => 30,
                'duration'     => 120,
                'event'        => 'progress',
            ]);

        // Watch up to 60s in Lesson 2 (15s real time at 2x speed)
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:10:15'));
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->standardLesson->id}/progress", [
                'current_time' => 60,
                'duration'     => 120,
                'event'        => 'progress',
            ]);

        $totalWatched = LessonProgress::where('user_id', $this->student->id)
            ->sum('watched_seconds');

        // Lesson 1: 47s, Lesson 2: 60s => 107s
        $this->assertEquals(107, $totalWatched);

        Carbon::setTestNow();
    }

    /**
     * 9. Forged payload event=ended, current_time=duration as first request does NOT complete.
     */
    public function test_forged_payload_event_ended_first_request_does_not_complete(): void
    {
        $response = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 47,
                'duration'     => 47,
                'event'        => 'ended',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertFalse($data['is_completed'], 'Forged single-heartbeat ended event must never complete lesson');
        $this->assertEquals(0, $data['watched_seconds']);
        $this->assertEquals(0, $data['percent']);

        // Lesson 2 must remain locked
        $nextCheck = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$this->standardLesson->id}/progress");
        $nextCheck->assertStatus(403)->assertJson(['code' => 'LESSON_LOCKED']);
    }

    /**
     * 10. Pause 120s then seek-to-end does NOT complete.
     */
    public function test_pause_120s_then_seek_to_end_does_not_complete(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00'));

        // Watch 10s legitimately
        $hb1 = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 10,
                'duration'     => 47,
                'event'        => 'progress',
            ]);
        $hb1->assertStatus(200);

        // Pause for 120 seconds of wall clock
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:02:00'));

        // Seek directly to end (47s)
        $seekHb = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 47,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        $seekHb->assertStatus(200);
        $data = $seekHb->json('data');

        $this->assertFalse($data['is_completed'], 'Pausing and seeking to end must not complete');
        $this->assertEquals(10, $data['watched_seconds']);
        $this->assertLessThan(95.0, (float) $data['percent']);

        Carbon::setTestNow();
    }

    /**
     * 11. Legit 1x, 1.5x, 2x viewing completes a 30-min lesson upon reaching >= 95% coverage.
     */
    public function test_legit_2x_viewing_completes_30_min_lesson(): void
    {
        // Create 30-min (1800s) lesson
        $longLesson = CourseChapterLecture::create([
            'course_chapter_id' => $this->chapter->id,
            'user_id'           => $this->student->id,
            'title'             => 'Long 30-Minute Deep Dive',
            'slug'              => 'long-30min-lesson',
            'chapter_order'     => 99,
            'duration_seconds'  => 1800,
            'is_active'         => true,
            'type'              => 'url',
            'free_preview'      => true, // preview so it is unlocked
        ]);

        $now = Carbon::parse('2026-10-04 10:00:00');
        Carbon::setTestNow($now);

        // Simulate 2x speed: advance 20s in video every 10s of real time
        // 86 steps * 20s = 1720s watched (1720 / 1800 = 95.56% >= 95% threshold)
        for ($step = 1; $step <= 86; $step++) {
            $currentTime = min(1800, $step * 20);
            $now = $now->addSeconds(10);
            Carbon::setTestNow($now);

            $this->actingAs($this->student)
                ->postJson("/api/lessons/{$longLesson->id}/progress", [
                    'current_time' => $currentTime,
                    'duration'     => 1800,
                    'event'        => 'progress',
                ]);
        }

        $check = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$longLesson->id}/progress");

        $check->assertStatus(200);
        $data = $check->json('data');

        $this->assertTrue($data['is_completed'], 'Reaching 95.5% coverage must complete the 30-min lesson');
        $this->assertEquals(100, $data['percent']);

        Carbon::setTestNow();
    }

    /**
     * 12. Two concurrent tabs don't regress or double count.
     */
    public function test_two_concurrent_tabs_do_not_regress_or_double_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00'));

        // Tab 1 watches [0, 20]
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 20,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        // Tab 2 resumes from 15s and watches to 35s
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:10'));
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->shortLesson->id}/progress", [
                'current_time' => 35,
                'duration'     => 47,
                'event'        => 'progress',
            ]);

        // Check progress
        $check = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$this->shortLesson->id}/progress");

        $data = $check->json('data');

        // Merged union [0, 20] U [15, 35] = [0, 35] => exactly 35s, not 55s
        $this->assertEquals(35, $data['watched_seconds']);
        $this->assertEquals(35, $data['max_position_seconds']);

        Carbon::setTestNow();
    }

    /**
     * 13. Query token rejected on general routes without Authorization Bearer header.
     */
    public function test_query_token_rejected_on_other_routes(): void
    {
        $response = $this->getJson("/api/user/certificates?token=fake_or_plain_token");
        $response->assertStatus(401);
    }

    /**
     * 14. Signed heartbeat token validates UID and LID, rejecting expired or foreign lesson.
     */
    public function test_signed_heartbeat_token_validates_uid_and_lid(): void
    {
        // 1. Valid signed token bound to student + shortLesson
        $validToken = \App\Http\Middleware\ValidateSignedHeartbeatToken::generateToken(
            $this->student->id,
            $this->shortLesson->id,
            60
        );

        $validRes = $this->postJson("/api/lessons/{$this->shortLesson->id}/progress?heartbeat_token={$validToken}", [
            'current_time' => 10,
            'duration'     => 47,
            'event'        => 'progress',
        ]);
        $validRes->assertStatus(200);

        // 2. Token bound to foreign lesson (standardLesson) used on shortLesson route -> 401
        $foreignToken = \App\Http\Middleware\ValidateSignedHeartbeatToken::generateToken(
            $this->student->id,
            $this->standardLesson->id,
            60
        );
        $foreignRes = $this->postJson("/api/lessons/{$this->shortLesson->id}/progress?heartbeat_token={$foreignToken}", [
            'current_time' => 10,
            'duration'     => 47,
            'event'        => 'progress',
        ]);
        $foreignRes->assertStatus(401);

        // 3. Expired token -> 401
        $expiredToken = base64_encode(json_encode([
            'uid' => $this->student->id,
            'lid' => (string) $this->shortLesson->id,
            'ts'  => now()->subSeconds(120)->timestamp,
            'sig' => hash_hmac('sha256', "{$this->student->id}:{$this->shortLesson->id}:" . now()->subSeconds(120)->timestamp, (string) config('app.key')),
        ]));

        $expiredRes = $this->postJson("/api/lessons/{$this->shortLesson->id}/progress?heartbeat_token={$expiredToken}", [
            'current_time' => 10,
            'duration'     => 47,
            'event'        => 'progress',
        ]);
        $expiredRes->assertStatus(401);
    }
}
