<?php

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\User;
use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_certificate_number_generator_produces_18_digits(): void
    {
        $user = User::factory()->create();
        $code = CourseCertificate::generateCertificateNumber($user->id);

        $this->assertEquals(18, strlen($code));
        $this->assertMatchesRegularExpression('/^[0-9]{18}$/', $code);
    }

    public function test_certificate_number_normalizer_removes_spaces_and_hyphens(): void
    {
        $input = " 5831 - 0492 - 7641 - 8052 - 73 ";
        $normalized = CourseCertificate::normalizeCertificateNumber($input);

        $this->assertEquals("583104927641805273", $normalized);
    }

    public function test_arabic_indic_digits_are_normalized_and_verified(): void
    {
        $arabicInput = "٥٨٣١٠٤٩٢٧٦٤١٨٠٥٢٧٣";
        $normalized = CourseCertificate::normalizeCertificateNumber($arabicInput);

        $this->assertEquals("583104927641805273", $normalized);
    }

    public function test_canonical_issuance_persists_full_snapshot_and_audit(): void
    {
        $instructor = User::factory()->create(['name' => 'Dr. Jane Doe']);
        $student = User::factory()->create(['name' => 'Ahmad Mahmoud']);
        $course = Course::factory()->create([
            'user_id' => $instructor->id,
            'title' => 'Mastering AI & Data Science',
            'certificate_enabled' => true,
        ]);

        $service = app(CertificateService::class);
        $certificate = $service->issueCertificate($student->id, $course->id, [
            'issuance_source'  => 'admin_manual',
            'allow_incomplete' => true,
            'issued_date'      => '2026-08-18',
        ]);

        $this->assertNotNull($certificate);
        $this->assertEquals(18, strlen($certificate->certificate_number));
        $this->assertEquals('Ahmad Mahmoud', $certificate->student_name);
        $this->assertEquals('Mastering AI & Data Science', $certificate->arabic_title);
        $this->assertEquals('Dr. Jane Doe', $certificate->instructor_name);
        $this->assertEquals('admin_manual', $certificate->issuance_source);
        $this->assertEquals('active', $certificate->status);
        $this->assertEquals('2026-08-18', $certificate->issued_date->toDateString());
        $this->assertNotNull($certificate->verification_token);
        $this->assertEquals(32, strlen($certificate->verification_token));
    }

    public function test_duplicate_issuance_is_idempotent_and_creates_only_one_certificate(): void
    {
        $student = User::factory()->create(['name' => 'Ahmad Student']);
        $course = Course::factory()->create(['title' => 'AI Course', 'certificate_enabled' => true]);

        $service = app(CertificateService::class);

        // First call
        $cert1 = $service->issueCertificate($student->id, $course->id, [
            'issuance_source'  => 'admin_manual',
            'allow_incomplete' => true,
        ]);

        // Second duplicate call
        $cert2 = $service->issueCertificate($student->id, $course->id, [
            'issuance_source'  => 'admin_manual',
            'allow_incomplete' => true,
        ]);

        $this->assertNotNull($cert1);
        $this->assertNotNull($cert2);
        $this->assertEquals($cert1->id, $cert2->id);
        $this->assertEquals($cert1->certificate_number, $cert2->certificate_number);

        // Verify only 1 record exists in DB
        $count = CourseCertificate::where('user_id', $student->id)->where('course_id', $course->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_public_verify_api_returns_valid_for_active_certificate(): void
    {
        $student = User::factory()->create(['name' => 'Ahmad Student']);
        $course = Course::factory()->create(['title' => 'Fullstack Mastery']);

        $certificate = CourseCertificate::create([
            'user_id'            => $student->id,
            'course_id'          => $course->id,
            'certificate_number' => '583104927641805273',
            'student_name'       => 'Ahmad Student',
            'arabic_title'       => 'Fullstack Mastery',
            'english_title'      => 'Fullstack Mastery',
            'instructor_name'    => 'Instructor Test',
            'issued_date'        => '2026-08-18',
            'status'             => 'active',
            'issuance_source'    => 'automatic',
            'verification_token' => 'a1b2c3d4e5f6789012345678abcdef01',
            'verification_code'  => 'ABC1234567',
        ]);

        // 1. Verify by 18-digit code
        $response = $this->getJson('/api/certificate/verify?code=583104927641805273');
        $response->assertStatus(200);
        $response->assertJson([
            'ok'       => true,
            'is_valid' => true,
            'valid'    => true,
            'status'   => 'valid',
            'data'     => [
                'certificate_number' => '583104927641805273',
                'student_name'       => 'Ahmad Student',
                'course_title'       => 'Fullstack Mastery',
                'status'             => 'valid',
            ],
        ]);

        // 2. Verify with spaced format
        $responseSpaced = $this->getJson('/api/certificate/verify?code=5831%200492%207641%208052%2073');
        $responseSpaced->assertStatus(200);
        $responseSpaced->assertJson(['ok' => true, 'is_valid' => true, 'status' => 'valid']);

        // 3. Verify by token
        $responseToken = $this->getJson('/api/certificate/verify?token=a1b2c3d4e5f6789012345678abcdef01');
        $responseToken->assertStatus(200);
        $responseToken->assertJson(['ok' => true, 'is_valid' => true, 'status' => 'valid']);

        // 4. Verify with Arabic-Indic digits
        $responseArabic = $this->getJson('/api/certificate/verify?code=٥٨٣١٠٤٩٢٧٦٤١٨٠٥٢٧٣');
        $responseArabic->assertStatus(200);
        $responseArabic->assertJson(['ok' => true, 'is_valid' => true, 'status' => 'valid']);
    }

    public function test_public_verify_api_does_not_leak_private_pii(): void
    {
        $student = User::factory()->create(['name' => 'Ahmad Student', 'email' => 'private.email@example.com']);
        $course = Course::factory()->create(['title' => 'Fullstack Mastery']);

        $certificate = CourseCertificate::create([
            'user_id'            => $student->id,
            'course_id'          => $course->id,
            'certificate_number' => '583104927641805273',
            'student_name'       => 'Ahmad Student',
            'arabic_title'       => 'Fullstack Mastery',
            'english_title'      => 'Fullstack Mastery',
            'instructor_name'    => 'Instructor Test',
            'issued_date'        => '2026-08-18',
            'status'             => 'active',
            'issuance_source'    => 'automatic',
            'verification_token' => 'a1b2c3d4e5f6789012345678abcdef01',
            'verification_code'  => 'ABC1234567',
        ]);

        $response = $this->getJson('/api/certificate/verify?code=583104927641805273');
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertArrayNotHasKey('email', $json['data'] ?? []);
        $this->assertArrayNotHasKey('user_id', $json['data'] ?? []);
        $this->assertArrayNotHasKey('id', $json['data'] ?? []);
        $this->assertArrayNotHasKey('password', $json['data'] ?? []);
        $this->assertArrayNotHasKey('qr_code_path', $json['data'] ?? []);
        $this->assertArrayNotHasKey('pdf_path', $json['data'] ?? []);
    }

    public function test_public_verify_api_returns_revoked_status_for_revoked_certificate(): void
    {
        $student = User::factory()->create(['name' => 'Revoked Student']);
        $course = Course::factory()->create(['title' => 'Security Course']);

        $certificate = CourseCertificate::create([
            'user_id'            => $student->id,
            'course_id'          => $course->id,
            'certificate_number' => '999988887777666655',
            'student_name'       => 'Revoked Student',
            'arabic_title'       => 'Security Course',
            'english_title'      => 'Security Course',
            'instructor_name'    => 'Instructor Test',
            'issued_date'        => '2026-08-18',
            'status'             => 'revoked',
            'revoked_at'         => now(),
            'revoked_reason'     => 'Policy Violation',
            'issuance_source'    => 'admin_manual',
            'verification_token' => 'b1b2c3d4e5f6789012345678abcdef02',
            'verification_code'  => 'REV1234567',
        ]);

        $response = $this->getJson('/api/certificate/verify?code=999988887777666655');
        $response->assertStatus(200);
        $response->assertJson([
            'ok'       => true,
            'is_valid' => false,
            'valid'    => false,
            'status'   => 'revoked',
            'data'     => [
                'certificate_number' => '999988887777666655',
                'status'             => 'revoked',
                'revoked_reason'     => 'Policy Violation',
            ],
        ]);
    }

    public function test_public_verify_api_returns_404_for_non_existent_certificate(): void
    {
        $response = $this->getJson('/api/certificate/verify?code=000000000000000000');
        $response->assertStatus(404);
        $response->assertJson([
            'ok'       => false,
            'is_valid' => false,
            'valid'    => false,
            'status'   => 'not_found',
        ]);
    }

    public function test_public_verify_api_returns_422_when_no_input_provided(): void
    {
        $response = $this->getJson('/api/certificate/verify');
        $response->assertStatus(422);
    }

    public function test_download_public_rejects_revoked_certificate_with_403(): void
    {
        $student = User::factory()->create();
        $course = Course::factory()->create(['title' => 'Sample Course']);

        $certificate = CourseCertificate::create([
            'user_id'            => $student->id,
            'course_id'          => $course->id,
            'certificate_number' => '123456789012345678',
            'student_name'       => 'Student',
            'arabic_title'       => 'Sample Course',
            'english_title'      => 'Sample Course',
            'instructor_name'    => 'Instructor',
            'issued_date'        => '2026-08-18',
            'status'             => 'revoked',
            'revoked_at'         => now(),
            'revoked_reason'     => 'Revoked for testing',
            'verification_token' => 'c1b2c3d4e5f6789012345678abcdef03',
            'verification_code'  => 'TESTCODE12',
        ]);

        $response = $this->getJson('/api/certificate/public/123456789012345678/download');
        $response->assertStatus(403);
    }
}
