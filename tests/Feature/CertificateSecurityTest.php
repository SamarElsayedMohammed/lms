<?php

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_user_cannot_download_another_users_certificate()
    {
        $owner = User::factory()->create(['is_active' => true]);
        $attacker = User::factory()->create(['is_active' => true]);
        $course = Course::factory()->create(['is_active' => true, 'status' => 'publish', 'approval_status' => 'approved']);

        // Create a certificate for the owner
        $certificate = CourseCertificate::create([
            'user_id'            => $owner->id,
            'course_id'          => $course->id,
            'certificate_number' => '583104927641805273',
            'student_name'       => $owner->name,
            'arabic_title'       => $course->title,
            'issued_date'        => now()->toDateString(),
            'status'             => 'active',
        ]);

        // Attacker attempts to download the certificate using the course ID
        $response = $this->actingAs($attacker, 'sanctum')->postJson('/api/certificate/course/download', [
            'course_id' => $course->id,
        ]);

        // The endpoint should block it because the attacker is not enrolled or hasn't completed it
        $response->assertStatus(403);
    }

    /** @test */
    public function a_revoked_certificate_is_not_publicly_downloadable(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $course = Course::factory()->create(['is_active' => true, 'status' => 'publish', 'approval_status' => 'approved']);

        $certificate = CourseCertificate::create([
            'user_id'            => $user->id,
            'course_id'          => $course->id,
            'certificate_number' => '999988887777666655',
            'student_name'       => $user->name,
            'arabic_title'       => $course->title,
            'issued_date'        => now()->toDateString(),
            'status'             => 'revoked',
            'revoked_at'         => now(),
        ]);

        $response = $this->getJson("/api/certificate/public/{$certificate->certificate_number}/download");

        $response->assertStatus(403);
    }
}
