<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\UserCourseTrack;
use App\Models\User;
use App\Models\VideoProgress;
use App\Services\VideoProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SequentialLessonMatrixSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;
    private User $enrolledStudent;
    private User $unenrolledStudent;
    private Course $course;
    private CourseChapter $chapter;
    private CourseChapterLecture $lecture1;
    private CourseChapterLecture $lecture2;
    private CourseChapterLecture $lecture3;
    private VideoProgressService $progressService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->progressService = app(VideoProgressService::class);

        $this->instructor = User::factory()->create(['is_active' => true]);
        $this->enrolledStudent = User::factory()->create(['is_active' => true]);
        $this->unenrolledStudent = User::factory()->create(['is_active' => true]);

        $this->course = Course::factory()->create([
            'user_id' => $this->instructor->id,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
            'sequential_access' => true,
        ]);

        $this->chapter = CourseChapter::factory()->create([
            'course_id' => $this->course->id,
            'chapter_order' => 1,
            'is_active' => true,
        ]);

        $this->lecture1 = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $this->chapter->id,
            'type' => 'file',
            'chapter_order' => 1,
            'is_active' => true,
            'duration_seconds' => 100,
            'free_preview' => false,
        ]);

        $this->lecture2 = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $this->chapter->id,
            'type' => 'file',
            'chapter_order' => 2,
            'is_active' => true,
            'duration_seconds' => 100,
            'free_preview' => false,
        ]);

        $this->lecture3 = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $this->chapter->id,
            'type' => 'file',
            'chapter_order' => 3,
            'is_active' => true,
            'duration_seconds' => 100,
            'free_preview' => false,
        ]);

        // Enroll only $this->enrolledStudent via UserCourseTrack
        UserCourseTrack::create([
            'user_id' => $this->enrolledStudent->id,
            'course_id' => $this->course->id,
        ]);
    }

    public function test_auth_check_runs_first_unauthenticated_request_rejected_with_401(): void
    {
        $response = $this->postJson("/api/lecture/{$this->lecture1->id}/progress", [
            'current_position' => 10,
            'total_duration' => 100,
        ]);

        $response->assertStatus(401);
    }

    public function test_enrollment_check_runs_before_lock_check_unenrolled_student_gets_403_course_access_required(): void
    {
        // Unenrolled student calling locked lecture 2 must receive "Course access required", not "LESSON_LOCKED"
        $response = $this->actingAs($this->unenrolledStudent, 'sanctum')
            ->postJson("/api/lecture/{$this->lecture2->id}/progress", [
                'current_position' => 10,
                'total_duration' => 100,
            ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Course access required', $response->getContent());
        $this->assertStringNotContainsString('LESSON_LOCKED', $response->getContent());
    }

    public function test_free_preview_lecture_is_accessible_even_when_prior_lessons_incomplete(): void
    {
        // Mark lecture 2 as free preview
        $this->lecture2->update(['free_preview' => true]);

        $this->assertTrue($this->progressService->canAccessNextLesson($this->enrolledStudent, $this->lecture2));

        // Free preview stream endpoint is allowed
        $response = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->getJson("/api/video/{$this->lecture2->id}/stream");

        // Should not be LESSON_LOCKED
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_course_creator_and_instructors_bypass_all_sequential_locks(): void
    {
        // Instructor is the course creator ($this->course->user_id === $this->instructor->id)
        $this->assertTrue($this->progressService->canAccessNextLesson($this->instructor, $this->lecture1));
        $this->assertTrue($this->progressService->canAccessNextLesson($this->instructor, $this->lecture2));
        $this->assertTrue($this->progressService->canAccessNextLesson($this->instructor, $this->lecture3));
    }

    public function test_already_completed_lesson_allows_replay_and_progress_updates(): void
    {
        // Complete lesson 1
        VideoProgress::create([
            'user_id' => $this->enrolledStudent->id,
            'lecture_id' => $this->lecture1->id,
            'watched_seconds' => 100,
            'total_seconds' => 100,
            'last_position' => 100,
            'watch_percentage' => 100,
            'is_completed' => true,
        ]);

        // Student can still replay and report progress on lesson 1
        $this->assertTrue($this->progressService->canAccessNextLesson($this->enrolledStudent, $this->lecture1));

        $response = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->postJson("/api/lecture/{$this->lecture1->id}/progress", [
                'current_position' => 20,
                'total_duration' => 100,
                'newly_watched_segments' => [2],
            ]);

        $response->assertOk();
    }

    public function test_check_access_api_returns_correct_allowed_boolean_for_unlocked_and_locked(): void
    {
        // Lesson 1 is unlocked
        $response1 = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->getJson("/api/lecture/{$this->lecture1->id}/check-access");
        $response1->assertOk()
            ->assertJsonPath('data.allowed', true);

        // Lesson 2 is locked initially
        $response2 = $this->actingAs($this->enrolledStudent, 'sanctum')
            ->getJson("/api/lecture/{$this->lecture2->id}/check-access");
        $response2->assertStatus(403)
            ->assertJsonPath('error_code', 'LESSON_LOCKED')
            ->assertJsonPath('allowed', false);
    }
}
