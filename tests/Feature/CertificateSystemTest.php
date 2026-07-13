<?php

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CertificateSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock CertificateService and VideoProgressService since they depend on external state/DB complexities
        $this->mock(\App\Services\CertificateService::class, function ($mock) {
            $mock->shouldReceive('checkCourseCompletionStatus')->andReturn(true);
        });

        $this->mock(\App\Services\VideoProgressService::class, function ($mock) {
            $mock->shouldReceive('getCourseProgress')->andReturn(100);
        });
    }

    public function test_user_can_generate_certificate_if_enrolled_and_completed()
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['title' => 'Test Course']);
        
        // Create enrollment
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'completed']);
        OrderCourse::factory()->create(['order_id' => $order->id, 'course_id' => $course->id]);

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/certificate/course/generate', [
            'course_id' => $course->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'message',
            'data' => [
                'studentName',
                'arabicCourseTitle',
                'englishCourseTitle',
                'date',
                'instructorName',
                'certificateId',
                'courseId'
            ]
        ]);

        $this->assertTrue($response->json('ok'));
        $this->assertDatabaseHas('course_certificates', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active'
        ]);
    }

    public function test_certificate_generation_is_idempotent()
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        
        $order = Order::factory()->create(['user_id' => $user->id, 'status' => 'completed']);
        OrderCourse::factory()->create(['order_id' => $order->id, 'course_id' => $course->id]);

        $this->actingAs($user, 'sanctum');

        // First call
        $response1 = $this->postJson('/api/certificate/course/generate', ['course_id' => $course->id]);
        $certId1 = $response1->json('data.certificateId');

        // Second call
        $response2 = $this->postJson('/api/certificate/course/generate', ['course_id' => $course->id]);
        $certId2 = $response2->json('data.certificateId');

        $this->assertEquals($certId1, $certId2);
        
        // Ensure only 1 certificate exists
        $count = CourseCertificate::where('user_id', $user->id)->where('course_id', $course->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_public_can_verify_certificate()
    {
        $user = User::factory()->create(['name' => 'John Doe']);
        $course = Course::factory()->create(['title' => 'Laravel Mastery']);
        
        $cert = CourseCertificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-00001-XXXXXX',
            'student_name' => 'John Doe',
            'english_title' => 'Laravel Mastery',
            'issued_date' => now(),
            'status' => 'active'
        ]);

        $response = $this->getJson('/api/certificate/verify?code=' . $cert->certificate_number);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'is_valid' => true,
            'data' => [
                'studentName' => 'John Doe',
                'certificateId' => 'CERT-2026-00001-XXXXXX',
                'englishCourseTitle' => 'Laravel Mastery',
            ]
        ]);
    }

    public function test_revoked_certificate_verification()
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        
        $cert = CourseCertificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-00001-XXXXXX',
            'issued_date' => now(),
            'status' => 'revoked',
            'revoked_at' => now()
        ]);

        $response = $this->getJson('/api/certificate/verify?code=' . $cert->certificate_number);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'is_valid' => false,
            'message' => 'Certificate has been revoked'
        ]);
    }
}
