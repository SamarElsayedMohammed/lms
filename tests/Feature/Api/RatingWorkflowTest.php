<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

final class RatingWorkflowTest extends TestCase
{
    public function test_unauthenticated_course_review_returns_401(): void
    {
        $response = $this->postJson('/api/course-reviews', [
            'course_id' => 1,
            'rating' => 5,
            'review' => 'Great course',
            'status' => 'approved',
        ]);

        $response->assertStatus(401);
    }
}
