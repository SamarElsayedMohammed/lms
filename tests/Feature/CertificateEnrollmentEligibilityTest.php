<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\User;
use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CertificateEnrollmentEligibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function completion_cannot_issue_a_certificate_without_a_completed_enrollment(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['certificate_enabled' => true]);

        $certificate = app(CertificateService::class)
            ->autoGenerateCertificate($user->id, $course->id);

        $this->assertNull($certificate);
    }
}
