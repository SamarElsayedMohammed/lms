<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\LessonProgress;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use App\Models\VideoProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private Course $course;
    private CourseChapter $chapter;
    private CourseChapterLecture $lesson1;
    private CourseChapterLecture $lesson2;

    protected function setUp(): void
    {
        parent::setUp();

        $instructor = User::factory()->create(['is_active' => true]);
        $this->student = User::factory()->create(['is_active' => true]);

        $this->course = Course::factory()->create([
            'user_id'             => $instructor->id,
            'title'               => 'Mastering Laravel & React',
            'certificate_enabled' => true,
            'sequential_access'   => true,
            'is_active'           => true,
            'status'              => 'publish',
            'approval_status'     => 'approved',
        ]);

        $this->chapter = CourseChapter::create([
            'course_id'     => $this->course->id,
            'user_id'       => $instructor->id,
            'title'         => 'Chapter 1: Foundations',
            'chapter_order' => 1,
            'is_active'     => true,
        ]);

        $this->lesson1 = CourseChapterLecture::create([
            'course_chapter_id' => $this->chapter->id,
            'user_id'           => $instructor->id,
            'title'             => 'Lesson 1: Introduction',
            'slug'              => 'lesson-1-intro',
            'chapter_order'     => 1,
            'duration_seconds'  => 60,
            'is_active'         => true,
            'type'              => 'url',
        ]);

        $this->lesson2 = CourseChapterLecture::create([
            'course_chapter_id' => $this->chapter->id,
            'user_id'           => $instructor->id,
            'title'             => 'Lesson 2: Advanced Topics',
            'slug'              => 'lesson-2-advanced',
            'chapter_order'     => 2,
            'duration_seconds'  => 120,
            'is_active'         => true,
            'type'              => 'url',
        ]);

        // Enroll student into the course via track & completed order
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
            'order_number'   => 'ORD-TEST-' . $this->student->id . '-' . microtime(true),
        ]);
        OrderCourse::create([
            'order_id'  => $order->id,
            'course_id' => $this->course->id,
            'price'     => 100,
            'tax_price' => 0,
        ]);
    }

    public function test_lesson_1_is_unlocked_and_lesson_2_is_locked_server_side(): void
    {
        // First lesson is always unlocked
        $response1 = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$this->lesson1->id}/progress");

        $response1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'lesson_id'    => $this->lesson1->id,
                    'is_completed' => false,
                ],
            ]);

        // Second lesson must return 403 LESSON_LOCKED
        $response2 = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$this->lesson2->id}/progress");

        $response2->assertStatus(403)
            ->assertJson([
                'code' => 'LESSON_LOCKED',
            ]);
    }

    public function test_heartbeat_saves_progress_and_never_regresses(): void
    {
        // Heartbeat 1: user watched 20s
        $hb1 = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->lesson1->id}/progress", [
                'current_time' => 20,
                'duration'     => 60,
                'event'        => 'progress',
            ]);

        $hb1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'lesson_id'             => $this->lesson1->id,
                    'last_position_seconds' => 20,
                    'is_completed'          => false,
                ],
            ]);

        $this->assertDatabaseHas('lesson_progress', [
            'user_id'               => $this->student->id,
            'lesson_id'             => $this->lesson1->id,
            'last_position_seconds' => 20,
            'is_completed'          => false,
        ]);

        // Heartbeat 2: user replayed backwards to 5s. Progress & max_position must NOT regress
        $hb2 = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->lesson1->id}/progress", [
                'current_time' => 5,
                'duration'     => 60,
                'event'        => 'progress',
            ]);

        $hb2->assertStatus(200);
        $data2 = $hb2->json('data');

        $this->assertEquals(5, $data2['last_position_seconds']);
        $this->assertGreaterThanOrEqual(20, $data2['max_position_seconds']);
        $this->assertGreaterThanOrEqual(20, $data2['watched_seconds']);
    }

    public function test_completing_lesson_1_unlocks_lesson_2(): void
    {
        // Legitimately watch lesson 1 covering intervals
        $this->completeLessonLegitimately($this->student, $this->lesson1);

        // Lesson 2 must now be unlocked (HTTP 200)
        $response2 = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$this->lesson2->id}/progress");

        $response2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'lesson_id'    => $this->lesson2->id,
                    'is_completed' => false,
                ],
            ]);
    }

    public function test_completing_course_issues_certificate_idempotently(): void
    {
        // Legitimately complete lesson 1 and lesson 2
        $this->completeLessonLegitimately($this->student, $this->lesson1);
        $this->completeLessonLegitimately($this->student, $this->lesson2);

        // Check Course Progress Overview
        $overview = $this->actingAs($this->student)
            ->getJson("/api/courses/{$this->course->id}/progress");

        $overview->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => [
                    'course_id'         => $this->course->id,
                    'overall_percent'   => 100,
                    'completed_lessons' => 2,
                    'total_lessons'     => 2,
                    'is_completed'      => true,
                ],
            ]);

        // Ensure certificate is issued
        $certCount = CourseCertificate::where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->count();

        $this->assertEquals(1, $certCount, 'Certificate must be issued exactly once upon 100% course completion');

        // Post another heartbeat to lesson 2 (e.g. replay)
        $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->lesson2->id}/progress", [
                'current_time' => 120,
                'duration'     => 120,
                'event'        => 'ended',
            ]);

        // Certificate count must remain exactly 1 (idempotency guarantee)
        $certCountAfter = CourseCertificate::where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->count();

        $this->assertEquals(1, $certCountAfter, 'Certificate must remain idempotent with no duplicate issuance');
    }

    public function test_seeking_to_end_does_not_complete_lesson_due_to_anti_cheat(): void
    {
        // Student jumps straight from 0:00 to 0:60 using seek bar (event = 'progress')
        $response = $this->actingAs($this->student)
            ->postJson("/api/lessons/{$this->lesson1->id}/progress", [
                'current_time' => 60,
                'duration'     => 60,
                'event'        => 'progress',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Anti-cheat caps watched_seconds: seek jump accrues 0 watch credit
        $this->assertEquals(60, $data['last_position_seconds']);
        $this->assertEquals(0, $data['watched_seconds']);
        $this->assertEquals(0, $data['percent']);
        $this->assertFalse($data['is_completed'], 'Seeking to the end without watching must not complete the lesson');

        // Lesson 2 must still remain locked
        $response2 = $this->actingAs($this->student)
            ->getJson("/api/lessons/{$this->lesson2->id}/progress");

        $response2->assertStatus(403)
            ->assertJson([
                'code' => 'LESSON_LOCKED',
            ]);
    }

    private function completeLessonLegitimately(User $user, CourseChapterLecture $lesson): void
    {
        $duration = (int) ($lesson->duration_seconds ?? 60);
        $time = \Carbon\Carbon::parse('2026-10-04 12:00:00');
        \Carbon\Carbon::setTestNow($time);

        $step = 10;
        for ($pos = $step; $pos < $duration; $pos += $step) {
            $this->actingAs($user)->postJson("/api/lessons/{$lesson->id}/progress", [
                'current_time' => $pos,
                'duration'     => $duration,
                'event'        => 'progress',
            ]);
            $time = $time->addSeconds(10);
            \Carbon\Carbon::setTestNow($time);
        }

        $this->actingAs($user)->postJson("/api/lessons/{$lesson->id}/progress", [
            'current_time' => $duration,
            'duration'     => $duration,
            'event'        => 'ended',
        ]);
        \Carbon\Carbon::setTestNow();
    }

    public function test_artisan_backfill_command_safely_syncs_legacy_records(): void
    {
        // Seed a legacy video_progress record
        VideoProgress::create([
            'user_id'          => $this->student->id,
            'lecture_id'       => $this->lesson1->id,
            'last_position'    => 45,
            'watched_seconds'  => 45,
            'watch_percentage' => 75.0,
            'is_completed'     => false,
            'completed_at'     => null,
        ]);

        // 1. Dry run should NOT write to lesson_progress
        $this->artisan('lms:backfill-lesson-progress', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('lesson_progress', [
            'user_id'   => $this->student->id,
            'lesson_id' => $this->lesson1->id,
        ]);

        // 2. Full run should backfill into lesson_progress
        $this->artisan('lms:backfill-lesson-progress')
            ->assertSuccessful();

        $this->assertDatabaseHas('lesson_progress', [
            'user_id'               => $this->student->id,
            'lesson_id'             => $this->lesson1->id,
            'course_id'             => $this->course->id,
            'last_position_seconds' => 45,
            'watched_seconds'       => 45,
            'percent'               => 75.0,
            'is_completed'          => false,
        ]);

        // 3. Re-running command must be completely idempotent
        $this->artisan('lms:backfill-lesson-progress')
            ->assertSuccessful();

        $count = LessonProgress::where('user_id', $this->student->id)
            ->where('lesson_id', $this->lesson1->id)
            ->count();

        $this->assertEquals(1, $count, 'Backfill must be strictly idempotent with exactly 1 row per user & lesson');
    }
}
