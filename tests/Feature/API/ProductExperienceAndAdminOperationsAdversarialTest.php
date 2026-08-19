<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Lecture\CourseLectureNote;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductExperienceAndAdminOperationsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private function createPublishedCourse(User $instructor): Course
    {
        return Course::factory()->create([
            'user_id'         => $instructor->id,
            'status'          => 'publish',
            'approval_status' => 'approved',
            'is_active'       => true,
            'is_free'         => false,
            'price'           => 100,
        ]);
    }

    private function createLecture(Course $course, int $duration = 300, bool $isFree = false): CourseChapterLecture
    {
        $chapter = CourseChapter::factory()->create([
            'course_id' => $course->id,
            'is_active' => true,
        ]);

        return CourseChapterLecture::factory()->create([
            'user_id'           => $course->user_id,
            'course_chapter_id' => $chapter->id,
            'duration_seconds'  => $duration,
            'is_free'           => $isFree,
            'free_preview'      => $isFree,
            'is_active'         => true,
        ]);
    }

    private function enrollStudentInCourse(User $student, Course $course): void
    {
        $order = Order::create([
            'order_number'   => 'ORD-' . uniqid(),
            'user_id'        => $student->id,
            'status'         => 'completed',
            'total_price'    => 100.00,
            'tax_price'      => 0,
            'final_price'    => 100.00,
            'payment_method' => 'card',
        ]);

        OrderCourse::create([
            'order_id'  => $order->id,
            'course_id' => $course->id,
            'price'     => 100.00,
            'tax_price' => 0.00,
        ]);
    }

    /**
     * VECTOR A1: Resume Learning returns next lecture and detailed progress
     */
    public function test_a1_resume_learning_returns_next_item_and_course_progress(): void
    {
        $instructor = User::factory()->create(['is_active' => true]);
        $student = User::factory()->create(['is_active' => true]);
        $course = $this->createPublishedCourse($instructor);
        $lecture = $this->createLecture($course, 300);

        // Enroll student via completed order
        $this->enrollStudentInCourse($student, $course);

        $response = $this->actingAs($student, 'sanctum')->getJson("/api/my-learning/{$course->id}");

        $response->assertOk()
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonPath('data.course.progress_percentage', 0)
            ->assertJsonPath('data.next_item.item_id', $lecture->id);
    }

    /**
     * VECTOR A2: Timestamped Video Notes creation, listing, and access boundaries
     */
    public function test_a2_timestamped_video_notes_lifecycle_and_access_security(): void
    {
        $instructor = User::factory()->create(['is_active' => true]);
        $student = User::factory()->create(['is_active' => true]);
        $unauthorizedUser = User::factory()->create(['is_active' => true]);
        $course = $this->createPublishedCourse($instructor);
        $lecture = $this->createLecture($course, 600);

        // 1. Unauthorized user cannot create notes on locked course (403)
        $this->actingAs($unauthorizedUser, 'sanctum')
            ->postJson("/api/lecture/{$lecture->id}/notes", [
                'video_timestamp_seconds' => 45,
                'note_text'               => 'Secret insight',
            ])
            ->assertStatus(403);

        // Enroll student
        $this->enrollStudentInCourse($student, $course);

        // 2. Validation rejection on invalid payload (422)
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/lecture/{$lecture->id}/notes", [
                'video_timestamp_seconds' => -10,
                'note_text'               => '',
            ])
            ->assertStatus(422);

        // 3. Student creates 2 notes at different timestamps
        $res1 = $this->actingAs($student, 'sanctum')
            ->postJson("/api/lecture/{$lecture->id}/notes", [
                'video_timestamp_seconds' => 120,
                'note_text'               => 'Important concept at 2:00',
            ])
            ->assertStatus(201);

        $noteId1 = $res1->json('data.note.id');

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/lecture/{$lecture->id}/notes", [
                'video_timestamp_seconds' => 30,
                'note_text'               => 'Introduction setup at 0:30',
            ])
            ->assertStatus(201);

        // 4. List notes ordered by timestamp ascending
        $listRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/lecture/{$lecture->id}/notes")
            ->assertOk();

        $notes = $listRes->json('data.notes');
        $this->assertCount(2, $notes);
        $this->assertEquals(30, $notes[0]['video_timestamp_seconds']);
        $this->assertEquals(120, $notes[1]['video_timestamp_seconds']);

        // 5. IDOR Attack: Unauthorized user cannot delete student's note (403)
        $this->actingAs($unauthorizedUser, 'sanctum')
            ->deleteJson("/api/lecture/notes/{$noteId1}")
            ->assertStatus(403);

        // 6. Student deletes their own note (200)
        $this->actingAs($student, 'sanctum')
            ->deleteJson("/api/lecture/notes/{$noteId1}")
            ->assertOk();

        $this->assertDatabaseMissing('course_lecture_notes', ['id' => $noteId1]);
    }

    /**
     * VECTOR A3: Lesson Resources / Attachments access control
     */
    public function test_a3_lesson_resources_access_control(): void
    {
        $instructor = User::factory()->create(['is_active' => true]);
        $student = User::factory()->create(['is_active' => true]);
        $course = $this->createPublishedCourse($instructor);
        $lecture = $this->createLecture($course, 300);

        // Unenrolled student gets 403 on course resources
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/get-resources?id={$course->id}")
            ->assertStatus(403);

        // Enroll student
        $this->enrollStudentInCourse($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/get-resources?id={$course->id}")
            ->assertOk();
    }

    /**
     * VECTOR B1: Helpdesk / Support groups endpoint
     */
    public function test_b1_helpdesk_support_endpoints(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/helpdesk/groups');
        $response->assertOk();
    }

    /**
     * VECTOR B2: Admin Notifications broadcast endpoint
     */
    public function test_b2_admin_notifications_index(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');
        $response->assertOk();
    }
}
