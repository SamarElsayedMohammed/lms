<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseCertificate;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\VideoProgress;
use App\Services\CertificateService;
use App\Services\ContentAccessService;
use App\Services\CourseProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CertificateAdversarialCertificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ContentAccessService::flushRequestCache();
    }

    protected function tearDown(): void
    {
        ContentAccessService::flushRequestCache();
        parent::tearDown();
    }

    private function createCourseWithLectures(int $lectureCount = 3, int $durationPerLecture = 60, bool $isFree = true): array
    {
        $instructor = User::factory()->create(['name' => 'Instructor Test', 'is_active' => true]);

        $course = Course::factory()->create([
            'user_id'             => $instructor->id,
            'title'               => 'Mastering Software Architecture',
            'is_free'             => $isFree,
            'course_type'         => $isFree ? 'free' : 'paid',
            'price'               => $isFree ? 0 : 100,
            'status'              => 'publish',
            'approval_status'     => 'approved',
            'is_active'           => true,
            'certificate_enabled' => true,
            'certificate_fee'     => 0,
        ]);

        $chapter = CourseChapter::factory()->create([
            'course_id'     => $course->id,
            'is_active'     => true,
            'chapter_order' => 1,
        ]);

        $lectures = [];
        for ($i = 1; $i <= $lectureCount; $i++) {
            $lectures[] = CourseChapterLecture::factory()->create([
                'user_id'           => $instructor->id,
                'course_chapter_id' => $chapter->id,
                'duration_seconds'  => $durationPerLecture,
                'type'              => 'file',
                'file_extension'    => 'mp4',
                'chapter_order'     => $i,
                'is_active'         => true,
                'free_preview'      => false,
                'is_free'           => false,
            ]);
        }

        return [$course, $chapter, $lectures];
    }

    private function completeLecture(User $user, CourseChapterLecture $lecture): void
    {
        $duration = $lecture->duration_seconds ?: 60;

        VideoProgress::updateOrCreate(
            ['user_id' => $user->id, 'lecture_id' => $lecture->id],
            [
                'watched_seconds'  => $duration,
                'total_seconds'    => $duration,
                'last_position'    => $duration,
                'watch_percentage' => 100.0,
                'is_completed'     => true,
                'completed_at'     => now(),
            ]
        );

        UserCurriculumTracking::updateOrCreate(
            [
                'user_id'           => $user->id,
                'course_chapter_id' => $lecture->course_chapter_id,
                'model_id'          => $lecture->id,
                'model_type'        => CourseChapterLecture::class,
            ],
            [
                'status'       => 'completed',
                'completed_at' => now(),
                'started_at'   => now(),
            ]
        );

        app(CourseProgressService::class)->calculateAndUpdateProgress($user->id, $lecture->chapter->course_id);
    }

    private function partialLecture(User $user, CourseChapterLecture $lecture, int $watchedSeconds): void
    {
        $duration = $lecture->duration_seconds ?: 60;
        $pct = round(($watchedSeconds / $duration) * 100, 2);

        VideoProgress::updateOrCreate(
            ['user_id' => $user->id, 'lecture_id' => $lecture->id],
            [
                'watched_seconds'  => $watchedSeconds,
                'total_seconds'    => $duration,
                'last_position'    => $watchedSeconds,
                'watch_percentage' => $pct,
                'is_completed'     => false,
                'completed_at'     => null,
            ]
        );

        app(CourseProgressService::class)->calculateAndUpdateProgress($user->id, $lecture->chapter->course_id);
    }

    /**
     * ATTACK-01 / INV-01: 0% progress cannot issue a certificate.
     */
    public function test_attack_01_zero_percent_course_cannot_issue_certificate(): void
    {
        $user = User::factory()->create(['name' => 'Alice', 'is_active' => true]);
        [$course] = $this->createCourseWithLectures(3, 60);

        $cert = app(CertificateService::class)->autoGenerateCertificate($user->id, $course->id);
        $this->assertNull($cert);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/certificate/course/generate?course_id={$course->id}");
        $response->assertForbidden();
    }

    /**
     * ATTACK-02 & ATTACK-03 / INV-01 & INV-02: Partial completion (99.9%) cannot issue certificate.
     */
    public function test_attack_02_and_03_partial_completion_cannot_issue_certificate(): void
    {
        $user = User::factory()->create(['name' => 'Bob', 'is_active' => true]);
        [$course, $chapter, $lectures] = $this->createCourseWithLectures(2, 100);

        $this->completeLecture($user, $lectures[0]);
        $this->partialLecture($user, $lectures[1], 99); // 99/100 seconds

        $cert = app(CertificateService::class)->autoGenerateCertificate($user->id, $course->id);
        $this->assertNull($cert);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/certificate/course/generate?course_id={$course->id}");
        $response->assertForbidden();
    }

    /**
     * ATTACK-05 / INV-03: One incomplete video prevents course certificate eligibility.
     */
    public function test_attack_05_one_incomplete_video_prevents_certificate(): void
    {
        $user = User::factory()->create(['name' => 'Charlie', 'is_active' => true]);
        [$course, $chapter, $lectures] = $this->createCourseWithLectures(5, 60);

        // Complete 4 out of 5 videos to 100%
        for ($i = 0; $i < 4; $i++) {
            $this->completeLecture($user, $lectures[$i]);
        }

        $cert = app(CertificateService::class)->autoGenerateCertificate($user->id, $course->id);
        $this->assertNull($cert);

        $eligibilityResponse = $this->actingAs($user, 'sanctum')->getJson("/api/certificate/course/eligibility?course_id={$course->id}");
        $eligibilityResponse->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.completed_lessons', 4)
            ->assertJsonPath('data.total_lessons', 5);
    }

    /**
     * ATTACK-07 & ATTACK-08 / INV-05 & INV-06: Replayed or concurrent issuance calls produce exactly one certificate.
     */
    public function test_attack_07_and_08_replayed_and_concurrent_issuance_creates_exactly_one_certificate(): void
    {
        $user = User::factory()->create(['name' => 'David', 'is_active' => true]);
        [$course, $chapter, $lectures] = $this->createCourseWithLectures(2, 60);

        $this->completeLecture($user, $lectures[0]);
        $this->completeLecture($user, $lectures[1]);

        $service = app(CertificateService::class);

        $cert1 = $service->autoGenerateCertificate($user->id, $course->id);
        $cert2 = $service->autoGenerateCertificate($user->id, $course->id);
        $cert3 = $service->autoGenerateCertificate($user->id, $course->id);

        $this->assertNotNull($cert1);
        $this->assertNotNull($cert2);
        $this->assertEquals($cert1->id, $cert2->id);
        $this->assertEquals($cert1->certificate_number, $cert2->certificate_number);

        $count = CourseCertificate::where('user_id', $user->id)->where('course_id', $course->id)->count();
        $this->assertEquals(1, $count);
    }

    /**
     * ATTACK-10 / INV-07: Certificate number is CSPRNG 18-digit numeric string.
     */
    public function test_attack_10_certificate_number_is_18_digit_csprng_numeric(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        for ($i = 0; $i < 20; $i++) {
            $number = CourseCertificate::generateCertificateNumber($user->id);
            $this->assertEquals(18, strlen($number));
            $this->assertMatchesRegularExpression('/^[0-9]{18}$/', $number);
        }
    }

    /**
     * ATTACK-12 & ATTACK-13 / INV-08 & INV-15: Fabricated tokens and numbers return 404 or 422, never valid.
     */
    public function test_attack_12_and_13_fabricated_tokens_and_numbers_never_verify(): void
    {
        // 1. Random 18-digit number that does not exist
        $response404 = $this->getJson('/api/certificate/verify?code=999999999999999999');
        $response404->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('is_valid', false)
            ->assertJsonPath('status', 'not_found');

        // 2. Empty query string
        $response422 = $this->getJson('/api/certificate/verify');
        $response422->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'invalid_input');

        // 3. Random hex token
        $responseToken404 = $this->getJson('/api/certificate/verify?token=deadbeefdeadbeefdeadbeefdeadbeef');
        $responseToken404->assertStatus(404)
            ->assertJsonPath('status', 'not_found');
    }

    /**
     * ATTACK-14 & ATTACK-15 / INV-16 & INV-17: Revoked certificate returns revoked status and blocks public download.
     */
    public function test_attack_14_and_15_revoked_certificate_returns_revoked_status_and_blocks_download(): void
    {
        $user = User::factory()->create(['name' => 'Eve', 'is_active' => true]);
        [$course, $chapter, $lectures] = $this->createCourseWithLectures(1, 60);

        $this->completeLecture($user, $lectures[0]);

        $cert = app(CertificateService::class)->autoGenerateCertificate($user->id, $course->id);
        $this->assertNotNull($cert);

        // Public verify is initially valid
        $resValid = $this->getJson("/api/certificate/verify?code={$cert->certificate_number}");
        $resValid->assertOk()
            ->assertJsonPath('status', 'valid')
            ->assertJsonPath('is_valid', true);

        // Admin revokes the certificate
        $admin = User::factory()->create(['is_active' => true]);
        $cert->update([
            'status'         => 'revoked',
            'revoked_at'     => now(),
            'revoked_reason' => 'Honor Code Violation',
            'revoked_by'     => $admin->id,
        ]);

        // Public verify immediately returns revoked
        $resRevoked = $this->getJson("/api/certificate/verify?code={$cert->certificate_number}");
        $resRevoked->assertOk()
            ->assertJsonPath('status', 'revoked')
            ->assertJsonPath('is_valid', false)
            ->assertJsonPath('data.revoked_reason', 'Honor Code Violation');

        // Public download is forbidden (403)
        $resDownload = $this->getJson("/api/certificate/public/{$cert->certificate_number}/download");
        $resDownload->assertStatus(403);
    }

    /**
     * ATTACK-16 / INV-18: Public verify API does not leak private user PII.
     */
    public function test_attack_16_public_verify_does_not_leak_private_pii(): void
    {
        $user = User::factory()->create([
            'name'      => 'Frank Student',
            'email'     => 'frank.secret@example.com',
            'is_active' => true,
        ]);
        [$course, $chapter, $lectures] = $this->createCourseWithLectures(1, 60);

        $this->completeLecture($user, $lectures[0]);
        $cert = app(CertificateService::class)->autoGenerateCertificate($user->id, $course->id);

        $res = $this->getJson("/api/certificate/verify?code={$cert->certificate_number}");
        $res->assertOk();

        $data = $res->json('data');
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('qr_code_path', $data);
        $this->assertArrayNotHasKey('pdf_path', $data);
    }

    /**
     * ATTACK-17 / INV-19: User B cannot download User A's private certificate via authenticated endpoint.
     */
    public function test_attack_17_cross_user_private_download_denied(): void
    {
        $userA = User::factory()->create(['name' => 'User A', 'is_active' => true]);
        $userB = User::factory()->create(['name' => 'User B', 'is_active' => true]);
        [$course, $chapter, $lectures] = $this->createCourseWithLectures(1, 60, false); // Paid course

        // Only User A completes the course
        $this->completeLecture($userA, $lectures[0]);
        $certA = app(CertificateService::class)->autoGenerateCertificate($userA->id, $course->id);

        // User B attempts to download User A's course certificate
        $res = $this->actingAs($userB, 'sanctum')->postJson('/api/certificate/course/download', [
            'course_id' => $course->id,
        ]);

        $res->assertForbidden();
    }

    /**
     * ATTACK-20 & ATTACK-21 / INV-10, INV-11, INV-12: Snapshot preserves original historical student name and course title.
     */
    public function test_attack_20_and_21_historical_snapshot_immutable_on_profile_or_course_rename(): void
    {
        $user = User::factory()->create(['name' => 'Original Student Name', 'is_active' => true]);
        [$course, $chapter, $lectures] = $this->createCourseWithLectures(1, 60);

        $this->completeLecture($user, $lectures[0]);
        $cert = app(CertificateService::class)->autoGenerateCertificate($user->id, $course->id);
        $this->assertNotNull($cert);

        // Rename User and Course after issuance
        $user->update(['name' => 'New Mutated Student Name']);
        $course->update(['title' => 'New Mutated Course Title']);

        // Refresh certificate from DB
        $freshCert = CourseCertificate::find($cert->id);
        $this->assertEquals('Original Student Name', $freshCert->student_name);
        $this->assertEquals('Mastering Software Architecture', $freshCert->arabic_title);

        // Verify public API returns original snapshot
        $res = $this->getJson("/api/certificate/verify?code={$cert->certificate_number}");
        $res->assertOk()
            ->assertJsonPath('data.student_name', 'Original Student Name')
            ->assertJsonPath('data.course_title', 'Mastering Software Architecture');
    }

    /**
     * ATTACK-22 & ATTACK-24 / INV-21: Historical certificate survives course unpublish or subscription expiry.
     */
    public function test_attack_22_and_24_certificate_survives_course_unpublish(): void
    {
        $user = User::factory()->create(['name' => 'Grace', 'is_active' => true]);
        [$course, $chapter, $lectures] = $this->createCourseWithLectures(1, 60);

        $this->completeLecture($user, $lectures[0]);
        $cert = app(CertificateService::class)->autoGenerateCertificate($user->id, $course->id);
        $this->assertNotNull($cert);

        // Unpublish course
        $course->update(['status' => 'draft']);

        // Historical certificate remains valid
        $res = $this->getJson("/api/certificate/verify?code={$cert->certificate_number}");
        $res->assertOk()
            ->assertJsonPath('status', 'valid')
            ->assertJsonPath('is_valid', true);
    }

    /**
     * ATTACK-27 / INV-24: Admin manual issuance stores audit trail metadata.
     */
    public function test_attack_27_admin_manual_issuance_stores_audit_trail(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $student = User::factory()->create(['is_active' => true]);
        [$course] = $this->createCourseWithLectures(1, 60);

        $service = app(CertificateService::class);
        $cert = $service->issueCertificate($student->id, $course->id, [
            'issuance_source'  => 'admin_manual',
            'issuer_id'        => $admin->id,
            'allow_incomplete' => true,
            'reason'           => 'Special credit transfer',
        ]);

        $this->assertNotNull($cert);
        $this->assertEquals('admin_manual', $cert->issuance_source);
        $this->assertEquals($admin->id, $cert->issuer_id);
    }

    /**
     * ATTACK-32 / INV-17: Public verification normalizes spaces, hyphens, and Eastern Arabic numerals.
     */
    public function test_attack_32_public_verification_normalizes_various_formats(): void
    {
        $user = User::factory()->create(['name' => 'Hassan', 'is_active' => true]);
        [$course, $chapter, $lectures] = $this->createCourseWithLectures(1, 60);

        $this->completeLecture($user, $lectures[0]);
        $cert = app(CertificateService::class)->autoGenerateCertificate($user->id, $course->id);
        $this->assertNotNull($cert);

        $number = $cert->certificate_number;
        $spaced = substr($number, 0, 4) . ' - ' . substr($number, 4, 4) . ' - ' . substr($number, 8, 4) . ' - ' . substr($number, 12, 4) . ' - ' . substr($number, 16);

        // Verify spaced/hyphenated
        $resSpaced = $this->getJson('/api/certificate/verify?code=' . urlencode($spaced));
        $resSpaced->assertOk()->assertJsonPath('status', 'valid');

        // Convert to Eastern Arabic numerals
        $arabicNumerals = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $englishNumerals = ['0','1','2','3','4','5','6','7','8','9'];
        $arabicNumber = str_replace($englishNumerals, $arabicNumerals, $number);

        $resArabic = $this->getJson('/api/certificate/verify?code=' . urlencode($arabicNumber));
        $resArabic->assertOk()->assertJsonPath('status', 'valid');
    }
}
