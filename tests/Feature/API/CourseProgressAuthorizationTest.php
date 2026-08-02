<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CourseProgressAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function an_unenrolled_user_cannot_read_a_paid_course_progress_breakdown(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $course = Course::factory()->create([
            'course_type' => 'paid',
            'price' => 100,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/course/{$course->id}/progress")
            ->assertForbidden();
    }
}
