<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\UserCourseTrack;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserCurriculumTracking;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileLearningSpaceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $instructor;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->user = User::factory()->create([
            'name'  => 'Learner Student',
            'email' => 'student@skillso.org',
        ]);
        $this->user->assignRole('student');

        $this->instructor = User::factory()->create([
            'name'  => 'Expert Instructor',
            'email' => 'instructor@skillso.org',
        ]);
        $this->instructor->assignRole('instructor');

        $this->category = Category::create([
            'name'      => 'Software Development',
            'slug'      => 'software-development',
            'is_active' => true,
        ]);
    }

    public function test_get_learning_space_aggregates_user_learning_data(): void
    {
        $course = Course::create([
            'title'       => 'Mastering Flutter Architecture',
            'slug'        => 'mastering-flutter-architecture',
            'user_id'     => $this->instructor->id,
            'category_id' => $this->category->id,
            'status'      => 'publish',
            'approval_status' => 'approved',
            'is_active'   => true,
            'level'       => 'all_levels',
            'course_type' => 'general',
            'price'       => 500,
            'is_free'     => false,
        ]);

        // Enroll user
        UserCourseTrack::create([
            'user_id'   => $this->user->id,
            'course_id' => $course->id,
            'status'    => 'started',
        ]);

        UserCourseProgress::create([
            'user_id'             => $this->user->id,
            'course_id'           => $course->id,
            'completed_items'     => 5,
            'total_items'         => 10,
            'progress_percentage' => 50.0,
            'last_accessed_at'    => now(),
            'status'              => 'in_progress',
        ]);

        // Add wishlist
        Wishlist::create([
            'user_id'   => $this->user->id,
            'course_id' => $course->id,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/mobile/learning-space');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonStructure([
            'data' => [
                'user',
                'summary' => [
                    'active_courses_count',
                    'completed_courses_count',
                    'total_enrolled_courses',
                    'certificates_count',
                    'learning_hours',
                    'saved_courses_count',
                ],
                'continue_learning',
                'active_courses',
                'saved_courses',
                'completed_courses',
                'certificates',
                'learning_activity',
            ],
        ]);

        $this->assertEquals(1, $response->json('data.summary.total_enrolled_courses'));
        $this->assertEquals(1, $response->json('data.summary.saved_courses_count'));
        $this->assertEquals('Mastering Flutter Architecture', $response->json('data.continue_learning.title'));
    }

    public function test_download_authorization_enforces_enrollment_entitlement(): void
    {
        $course = Course::create([
            'title'       => 'Protected Course',
            'slug'        => 'protected-course',
            'user_id'     => $this->instructor->id,
            'category_id' => $this->category->id,
            'status'      => 'publish',
            'approval_status' => 'approved',
            'is_active'   => true,
            'level'       => 'all_levels',
            'course_type' => 'general',
            'price'       => 1000,
            'is_free'     => false,
        ]);

        $chapter = CourseChapter::create([
            'user_id'   => $this->instructor->id,
            'course_id' => $course->id,
            'title'     => 'Chapter 1',
            'order'     => 1,
        ]);

        $lecture = CourseChapterLecture::create([
            'user_id'           => $this->instructor->id,
            'course_chapter_id' => $chapter->id,
            'title'             => 'Introduction Lesson',
            'slug'              => 'introduction-lesson',
            'type'              => 'file',
            'file'              => 'https://stream.skillso.org/lecture1.mp4',
            'minutes'           => 10,
            'chapter_order'     => 1,
        ]);

        Sanctum::actingAs($this->user);

        // 1. Non-enrolled user -> 403 Forbidden
        $responseUnauthorized = $this->postJson('/api/mobile/downloads/authorize', [
            'course_id'  => $course->id,
            'lecture_id' => $lecture->id,
        ]);

        $responseUnauthorized->assertStatus(403);
        $this->assertEquals('not_entitled', $responseUnauthorized->json('entitlement_status'));

        // 2. Enroll user -> 200 Authorized
        UserCourseTrack::create([
            'user_id'   => $this->user->id,
            'course_id' => $course->id,
            'status'    => 'started',
        ]);

        $responseAuthorized = $this->postJson('/api/mobile/downloads/authorize', [
            'course_id'  => $course->id,
            'lecture_id' => $lecture->id,
        ]);

        $responseAuthorized->assertStatus(200);
        $responseAuthorized->assertJsonPath('ok', true);
        $responseAuthorized->assertJsonPath('data.is_authorized', true);
        $responseAuthorized->assertJsonPath('data.course_id', $course->id);
        $responseAuthorized->assertJsonPath('data.lecture.id', $lecture->id);
    }

    public function test_offline_progress_synchronization_updates_forward_without_regression(): void
    {
        $course = Course::create([
            'title'       => 'Fullstack Architecture',
            'slug'        => 'fullstack-architecture',
            'user_id'     => $this->instructor->id,
            'category_id' => $this->category->id,
            'status'      => 'publish',
            'approval_status' => 'approved',
            'is_active'   => true,
            'level'       => 'all_levels',
            'course_type' => 'free',
            'is_free'     => true,
        ]);

        $chapter = CourseChapter::create([
            'user_id'   => $this->instructor->id,
            'course_id' => $course->id,
            'title'     => 'Chapter 1',
            'order'     => 1,
        ]);

        $lecture = CourseChapterLecture::create([
            'user_id'           => $this->instructor->id,
            'course_chapter_id' => $chapter->id,
            'title'             => 'Architecture Lesson 1',
            'slug'              => 'architecture-lesson-1',
            'type'              => 'file',
            'file'              => 'https://stream.skillso.org/arch1.mp4',
            'minutes'           => 20,
            'chapter_order'     => 1,
        ]);

        Sanctum::actingAs($this->user);

        $payload = [
            'updates' => [
                [
                    'course_id'             => $course->id,
                    'lecture_id'            => $lecture->id,
                    'completed'             => true,
                    'time_spent_seconds'    => 900,
                    'last_position_seconds' => 900.5,
                ],
            ],
        ];

        $response = $this->postJson('/api/mobile/learning/sync-progress', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('data.synced_items_count', 1);

        $this->assertDatabaseHas('user_curriculum_trackings', [
            'user_id'           => $this->user->id,
            'course_chapter_id' => $chapter->id,
            'model_id'          => $lecture->id,
            'status'            => 'completed',
        ]);

        $this->assertDatabaseHas('user_course_progress', [
            'user_id'             => $this->user->id,
            'course_id'           => $course->id,
            'progress_percentage' => 100.0,
            'status'              => 'completed',
        ]);
    }
}
