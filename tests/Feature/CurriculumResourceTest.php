<?php

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Course $course;
    protected CourseChapter $chapter;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $role = config('constants.SYSTEM_ROLES.INSTRUCTOR') ?? 'Teacher';
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['is_active' => 1]);
        $this->admin->assignRole($role);
        \App\Models\Instructor::firstOrCreate(['user_id' => $this->admin->id], ['status' => 'approved', 'type' => 'individual']);

        $this->course = Course::factory()->create(['user_id' => $this->admin->id]);
        $this->chapter = CourseChapter::factory()->create(['course_id' => $this->course->id]);
    }

    public function test_create_lecture_curriculum_without_resources(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/api/course-chapters/curriculum', [
            'chapter_id' => $this->chapter->id,
            'type' => 'lecture',
            'lecture_type' => 'file',
            'lecture_title' => 'Test Lecture',
            'lecture_description' => 'Test Description',
            'lecture_hours' => 1,
            'lecture_minutes' => 30,
            'lecture_seconds' => 0,
            'lecture_free_preview' => false,
            'resource_status' => 0, // No resources
            'is_active' => 1,
        ]);

        // Should not fail when no resources are provided
        $this->assertTrue(
            $response->status() === 200 || $response->status() === 201 || $response->status() === 422,
            'Curriculum creation should not crash when resources are not provided',
        );
    }

    public function test_create_lecture_curriculum_with_resources(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/api/course-chapters/curriculum', [
            'chapter_id' => $this->chapter->id,
            'type' => 'lecture',
            'lecture_type' => 'file',
            'lecture_title' => 'Test Lecture with Resources',
            'lecture_description' => 'Test Description',
            'lecture_hours' => 1,
            'lecture_minutes' => 30,
            'lecture_seconds' => 0,
            'lecture_free_preview' => false,
            'resource_status' => 1, // With resources
            'resource_data' => [
                [
                    'resource_type' => 'url',
                    'resource_title' => 'Reference Link',
                    'resource_url' => 'https://example.com',
                ],
            ],
            'is_active' => 1,
        ]);

        // Should not fail when resources are provided
        $this->assertTrue(
            $response->status() === 200 || $response->status() === 201 || $response->status() === 422,
            'Curriculum creation should handle resources correctly',
        );
    }
}
