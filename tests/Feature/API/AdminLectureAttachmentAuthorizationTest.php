<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminLectureAttachmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_regular_authenticated_student_cannot_manage_lecture_attachments(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/lecture/1/attachments')
            ->assertForbidden();
    }

    /** @test */
    public function a_regular_authenticated_student_cannot_manage_assignments_or_review_approvals(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/assignments')
            ->assertForbidden();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/reviews/pending')
            ->assertForbidden();
    }
}
