<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SensitiveCourseEndpointsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function dashboard_analytics_are_not_publicly_routable(): void
    {
        $this->getJson('/api/dashboard-data')->assertUnauthorized();
        $this->getJson('/api/dashboard-charts')->assertUnauthorized();
    }

    /** @test */
    public function quiz_attempt_endpoints_are_unroutable_in_video_only_lms(): void
    {
        $this->getJson('/api/get-quiz-attempt-details?attempt_id=1')
            ->assertNotFound();
    }

    /** @test */
    public function user_details_require_an_authenticated_user(): void
    {
        $this->getJson('/api/get-user-details')
            ->assertUnauthorized();
    }
}
