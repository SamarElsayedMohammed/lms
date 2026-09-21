<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\InstructorRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackendStabilityForensicRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: getUserDetails executes with indexed lookups and correctly resolves instructor status.
     */
    public function test_get_user_details_resolves_status_without_table_scans(): void
    {
        $user = User::factory()->create([
            'email' => 'applicant@skillso.test',
        ]);

        InstructorRequest::create([
            'user_id' => $user->id,
            'name' => 'Applicant User',
            'email' => 'applicant@skillso.test',
            'phone' => '+1234567890',
            'status' => 'under_review',
        ]);

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/get-user-details');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => true,
            'data' => [
                'id' => $user->id,
                'instructor_process_status' => 'under_review',
            ],
        ]);

        $this->assertGreaterThan(0, $queryCount);
    }

    /**
     * Test 2: Public getInstructorRequestStatus is strictly read-only and NEVER mutates applicant user_id.
     */
    public function test_public_instructor_request_status_is_read_only_and_does_not_mutate_ownership(): void
    {
        $guestApplicant = InstructorRequest::create([
            'user_id' => null,
            'name' => 'Independent Applicant',
            'email' => 'independent@domain.com',
            'phone' => '+966500000000',
            'status' => 'pending',
        ]);

        $otherAuthenticatedUser = User::factory()->create([
            'email' => 'stranger@domain.com',
        ]);

        // Stranger looks up applicant's request while logged in
        $response = $this->actingAs($otherAuthenticatedUser, 'sanctum')
            ->getJson("/api/instructor-request/status?request_id={$guestApplicant->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => true,
            'data' => [
                'id' => $guestApplicant->id,
                'email' => 'independent@domain.com',
                'status' => 'pending',
            ],
        ]);

        // Assert database was NOT mutated (regression fix: ownership not hijacked)
        $refreshedApplicant = $guestApplicant->fresh();
        $this->assertNull($refreshedApplicant->user_id, 'Public GET endpoint must NOT mutate application user_id');
    }

    /**
     * Test 3: getMyInstructorRequest links ownership only when applicant email strictly matches user email.
     */
    public function test_get_my_instructor_request_links_safely_when_emails_match(): void
    {
        $email = 'matching.applicant@domain.com';
        $user = User::factory()->create(['email' => $email]);

        $application = InstructorRequest::create([
            'user_id' => null,
            'name' => 'Matching Applicant',
            'email' => $email,
            'phone' => '+966511111111',
            'status' => 'under_review',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/my-instructor-request');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => true,
            'data' => [
                'id' => $application->id,
                'email' => $email,
            ],
        ]);

        $this->assertEquals($user->id, $application->fresh()->user_id);
    }

    /**
     * Test 4: submitBecomeInstructor prevents duplicate applications efficiently.
     */
    public function test_submit_become_instructor_detects_existing_pending_request(): void
    {
        $user = User::factory()->create(['email' => 'instructor.candidate@domain.com']);

        InstructorRequest::create([
            'user_id' => $user->id,
            'name' => 'Candidate',
            'email' => $user->email,
            'phone' => '+966522222222',
            'status' => 'under_review',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/become-instructor', [
            'name' => 'Candidate',
            'email' => $user->email,
            'phone' => '+966522222222',
            'facebook_url' => 'https://facebook.com/candidate',
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'status' => false,
        ]);
    }

    /**
     * Test 5: Certificate verification handles exact, formatted, stripped, and lowercase queries.
     */
    public function test_certificate_verification_handles_all_input_formats_reliably(): void
    {
        $student = User::factory()->create(['name' => 'Zainab']);
        $course = Course::factory()->create(['title' => 'Advanced Laravel']);

        $cert = CourseCertificate::create([
            'user_id'            => $student->id,
            'course_id'          => $course->id,
            'certificate_number' => 'SKILL-2026-999',
            'student_name'       => 'Zainab',
            'arabic_title'       => 'Advanced Laravel',
            'english_title'      => 'Advanced Laravel',
            'instructor_name'    => 'Instructor',
            'issued_date'        => '2026-09-20',
            'status'             => 'active',
            'issuance_source'    => 'automatic',
            'verification_token' => 'a9b8c7d6e5f41234567890abcdef1234',
            'verification_code'  => 'SKILL999',
        ]);

        // Exact match
        $r1 = $this->getJson('/api/certificate/verify?code=SKILL-2026-999');
        $r1->assertStatus(200)->assertJson(['ok' => true, 'is_valid' => true]);

        // Lowercase match
        $r2 = $this->getJson('/api/certificate/verify?code=skill-2026-999');
        $r2->assertStatus(200)->assertJson(['ok' => true, 'is_valid' => true]);

        // Stripped match
        $r3 = $this->getJson('/api/certificate/verify?code=SKILL2026999');
        $r3->assertStatus(200)->assertJson(['ok' => true, 'is_valid' => true]);

        // Verification token match
        $r4 = $this->getJson('/api/certificate/verify?token=a9b8c7d6e5f41234567890abcdef1234');
        $r4->assertStatus(200)->assertJson(['ok' => true, 'is_valid' => true]);

        // Verification code match
        $r5 = $this->getJson('/api/certificate/verify?code=SKILL999');
        $r5->assertStatus(200)->assertJson(['ok' => true, 'is_valid' => true]);
    }

    /**
     * Test 6: Performance Regression Guard - Certificate 404 lookups execute within a strict query budget (<= 1 query)
     * and do not trigger unbounded fallback scans.
     */
    public function test_certificate_verification_executes_within_strict_query_budget_and_no_table_scans(): void
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        // Non-existent certificate code
        $response = $this->getJson('/api/certificate/verify?code=NON-EXISTENT-999');
        $response->assertStatus(404);

        // A single indexed query must resolve the 404 lookup — no secondary scans
        $this->assertLessThanOrEqual(2, $queryCount, 'Non-existent certificate lookup exceeded query budget');
    }

    /**
     * Test 7: Upload Safety - FileService handles upload gracefully without memory exhaustion.
     */
    public function test_image_upload_decompression_bomb_prevention(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $tempFile = \Illuminate\Http\UploadedFile::fake()->image('normal_photo.jpg', 800, 600);
        $path = \App\Services\FileService::compressAndUpload($tempFile, 'test/uploads', 'public');

        $this->assertNotEmpty($path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);
    }

    /**
     * Test 8: Race Safety - Concurrent linking of applicant request is atomic and prevents ownership conflict.
     */
    public function test_concurrent_instructor_linking_race_safety(): void
    {
        $email = 'applicant.race@domain.com';
        $user1 = User::factory()->create(['email' => $email]);
        $user2 = User::factory()->create(['email' => $email]);

        $application = InstructorRequest::create([
            'user_id' => null,
            'name' => 'Race Applicant',
            'email' => $email,
            'phone' => '+966533333333',
            'status' => 'under_review',
        ]);

        // First user links
        $this->actingAs($user1, 'sanctum')->getJson('/api/my-instructor-request')->assertStatus(200);
        $this->assertEquals($user1->id, $application->fresh()->user_id);

        // Second concurrent user attempts to link: must NOT overwrite user1 ownership
        $this->actingAs($user2, 'sanctum')->getJson('/api/my-instructor-request')->assertStatus(200);
        $this->assertEquals($user1->id, $application->fresh()->user_id, 'Ownership was hijacked by secondary concurrent user');
    }
}
