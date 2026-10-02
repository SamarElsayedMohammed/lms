<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use App\Models\VideoProgress;
use App\Services\VideoProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SequentialLessonUnlockingTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
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

        $this->student = User::factory()->create(['is_active' => true]);

        $this->course = Course::factory()->create([
            'course_type' => 'free',
            'sequential_access' => true,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
        ]);

        $this->chapter = CourseChapter::factory()->create([
            'course_id' => $this->course->id,
            'chapter_order' => 1,
            'is_active' => true,
        ]);

        $this->lecture1 = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $this->chapter->id,
            'chapter_order' => 1,
            'type' => 'file',
            'file_extension' => 'mp4',
            'duration_seconds' => 100,
            'is_active' => true,
        ]);

        $this->lecture2 = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $this->chapter->id,
            'chapter_order' => 2,
            'type' => 'file',
            'file_extension' => 'mp4',
            'duration_seconds' => 100,
            'is_active' => true,
        ]);

        $this->lecture3 = CourseChapterLecture::factory()->create([
            'course_chapter_id' => $this->chapter->id,
            'chapter_order' => 3,
            'type' => 'file',
            'file_extension' => 'mp4',
            'duration_seconds' => 100,
            'is_active' => true,
        ]);
    }

    public function test_first_lesson_is_unlocked_and_subsequent_lessons_are_locked(): void
    {
        // First lesson is always accessible
        $this->assertTrue($this->progressService->canAccessNextLesson($this->student, $this->lecture1));

        // Lessons 2 and 3 are locked initially
        $this->assertFalse($this->progressService->canAccessNextLesson($this->student, $this->lecture2));
        $this->assertFalse($this->progressService->canAccessNextLesson($this->student, $this->lecture3));
    }

    public function test_completing_lesson_1_unlocks_lesson_2_while_lesson_3_remains_locked(): void
    {
        // Mark lesson 1 as completed
        VideoProgress::create([
            'user_id' => $this->student->id,
            'lecture_id' => $this->lecture1->id,
            'watched_seconds' => 100,
            'total_seconds' => 100,
            'last_position' => 100,
            'watch_percentage' => 100,
            'is_completed' => true,
        ]);

        // Lesson 1 remains accessible (replay)
        $this->assertTrue($this->progressService->canAccessNextLesson($this->student, $this->lecture1));

        // Lesson 2 is now unlocked!
        $this->assertTrue($this->progressService->canAccessNextLesson($this->student, $this->lecture2));

        // Lesson 3 remains locked until lesson 2 is completed
        $this->assertFalse($this->progressService->canAccessNextLesson($this->student, $this->lecture3));
    }

    public function test_get_course_api_returns_authoritative_is_locked_flags(): void
    {
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/get-course?id={$this->course->id}")
            ->assertOk();

        $data = $response->json('data');
        $curriculum = $data['chapters'][0]['curriculum'];

        $this->assertCount(3, $curriculum);

        // Lesson 1 is unlocked (is_locked = false)
        $this->assertEquals($this->lecture1->id, $curriculum[0]['id']);
        $this->assertFalse($curriculum[0]['is_locked']);

        // Lesson 2 is locked (is_locked = true)
        $this->assertEquals($this->lecture2->id, $curriculum[1]['id']);
        $this->assertTrue($curriculum[1]['is_locked']);

        // Lesson 3 is locked (is_locked = true)
        $this->assertEquals($this->lecture3->id, $curriculum[2]['id']);
        $this->assertTrue($curriculum[2]['is_locked']);
    }

    public function test_admin_bypasses_sequential_locking(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->assertTrue($this->progressService->canAccessNextLesson($admin, $this->lecture1));
        $this->assertTrue($this->progressService->canAccessNextLesson($admin, $this->lecture2));
        $this->assertTrue($this->progressService->canAccessNextLesson($admin, $this->lecture3));
    }

    public function test_course_with_sequential_access_disabled_allows_all_lessons(): void
    {
        $this->course->update(['sequential_access' => false]);
        $this->course->refresh();

        $this->assertTrue($this->progressService->canAccessNextLesson($this->student, $this->lecture1));
        $this->assertTrue($this->progressService->canAccessNextLesson($this->student, $this->lecture2));
        $this->assertTrue($this->progressService->canAccessNextLesson($this->student, $this->lecture3));
    }
}
