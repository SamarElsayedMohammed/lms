<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Course\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class RateLimitingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clearResolvedInstances();
    }

    public function test_course_view_endpoint_throttles_after_rate_limit(): void
    {
        $course = Course::factory()->create([
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
        ]);

        // Route has throttle:30,1
        // Perform 30 requests - should succeed (status 200 or whatever controller returns)
        for ($i = 0; $i < 30; $i++) {
            $response = $this->postJson('/api/course-view', ['course_id' => $course->id]);
            $this->assertNotEquals(429, $response->getStatusCode(), "Request #{$i} should not be throttled");
        }

        // 31st request must receive 429 Too Many Requests
        $response31 = $this->postJson('/api/course-view', ['course_id' => $course->id]);
        $response31->assertStatus(429);
    }

    public function test_become_instructor_endpoint_throttles_after_5_requests(): void
    {
        // Route has throttle:5,1
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/become-instructor', [
                'name' => 'Instructor Candidate',
                'email' => "candidate{$i}@example.com",
            ]);
            $this->assertNotEquals(429, $response->getStatusCode(), "Request #{$i} should not be throttled");
        }

        // 6th request must be throttled with 429
        $response6 = $this->postJson('/api/become-instructor', [
            'name' => 'Instructor Candidate',
            'email' => 'candidate6@example.com',
        ]);
        $response6->assertStatus(429);
    }
}
