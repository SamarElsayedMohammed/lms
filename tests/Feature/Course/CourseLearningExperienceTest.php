<?php

namespace Tests\Feature\Course;

use App\Models\Course\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseLearningExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_course_eligibility_dto(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create([
            'status' => 'publish',
            'approval_status' => 'approved',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/certificate/course/eligibility?course_id={$course->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'eligible',
                    'reason_code',
                    'course_progress',
                    'completed_lessons',
                    'total_lessons',
                    'remaining_lessons',
                    'certificate_issued',
                ]
            ]);
    }

    public function test_unauthenticated_user_cannot_access_certificate_eligibility(): void
    {
        $course = Course::factory()->create();

        $response = $this->getJson("/api/certificate/course/eligibility?course_id={$course->id}");

        $response->assertStatus(401);
    }
}
