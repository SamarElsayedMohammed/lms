<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Models\CourseCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_user_cannot_download_another_users_certificate()
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $course = Course::factory()->create();

        // Create a certificate for the owner
        $certificate = CourseCertificate::factory()->create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-12345',
        ]);

        // Attacker attempts to download the certificate using the course ID
        $response = $this->actingAs($attacker)->postJson('/api/v1/certificate/course/download', [
            'course_id' => $course->id,
        ]);

        // The endpoint should block it because the attacker is not enrolled or hasn't completed it
        $response->assertStatus(403);
    }
}
