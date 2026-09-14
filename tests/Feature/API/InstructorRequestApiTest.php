<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\InstructorRequest;
use App\Models\User;
use App\Support\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class InstructorRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RoleManager::ensureCanonicalRolesExist();
        Storage::fake('public');
    }

    public function test_public_user_can_submit_become_instructor_request_with_files(): void
    {
        $cv = UploadedFile::fake()->create('curriculum_vitae.pdf', 500, 'application/pdf');
        $avatar = UploadedFile::fake()->image('profile.jpg', 200, 200);

        $payload = [
            'first_name' => 'أحمد',
            'last_name' => 'المهندس',
            'email' => 'ahmed.engineer@example.com',
            'phone' => '+966501234567',
            'country' => 'SA',
            'nationality' => 'سعودي',
            'job_title' => 'كبير مهندسي البرمجيات',
            'company' => 'شركة التقنية المتقدمة',
            'years_of_experience' => 8,
            'specialty' => 'تطوير الويب والذكاء الاصطناعي',
            'experience_bio' => 'خبرة تزيد عن 8 سنوات في تصميم وتطوير النظم السحابية وتدريب أكثر من 1000 مهندس.',
            'linkedin_url' => 'https://linkedin.com/in/ahmed-engineer',
            'facebook_url' => 'https://facebook.com/ahmed.engineer',
            'website_url' => 'https://ahmed-engineer.dev',
            'intro_video_type' => 'url',
            'intro_video_url' => 'https://youtube.com/watch?v=dQw4w9WgXcQ',
            'cv' => $cv,
            'profile_image' => $avatar,
        ];

        $response = $this->post('/api/become-instructor', $payload);

        $response->assertOk();
        $response->assertJsonPath('error', false);
        $response->assertJsonStructure([
            'data' => [
                'request_id',
                'reference_code',
                'status',
                'status_label',
                'created_at',
            ],
        ]);

        $this->assertDatabaseHas('instructor_requests', [
            'email' => 'ahmed.engineer@example.com',
            'first_name' => 'أحمد',
            'last_name' => 'المهندس',
            'name' => 'أحمد المهندس',
            'facebook_url' => 'https://facebook.com/ahmed.engineer',
            'job_title' => 'كبير مهندسي البرمجيات',
            'company' => 'شركة التقنية المتقدمة',
            'status' => 'pending',
            'cv_original_name' => 'curriculum_vitae.pdf',
        ]);
    }

    public function test_submission_fails_with_invalid_email_or_missing_required_fields(): void
    {
        $response = $this->postJson('/api/become-instructor', [
            'first_name' => 'أحمد',
            // Missing email, phone, and facebook_url
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', true);
        $response->assertJsonStructure([
            'errors' => [
                'email',
                'phone',
                'facebook_url',
            ],
        ]);
    }

    public function test_submission_fails_when_facebook_url_is_missing(): void
    {
        $payload = [
            'first_name' => 'أحمد',
            'last_name' => 'المهندس',
            'email' => 'ahmed.missingfb@example.com',
            'phone' => '+966501234567',
            'specialty' => 'تطوير الويب',
            'experience_bio' => 'خبرة أكثر من عشر سنوات في التدريب والبرمجة والتطوير',
        ];

        $response = $this->postJson('/api/become-instructor', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error', true);
        $response->assertJsonStructure([
            'errors' => [
                'facebook_url',
            ],
        ]);
    }

    public function test_submission_fails_when_facebook_url_is_invalid_domain(): void
    {
        $payload = [
            'first_name' => 'أحمد',
            'last_name' => 'المهندس',
            'email' => 'ahmed.badfb@example.com',
            'phone' => '+966501234567',
            'specialty' => 'تطوير الويب',
            'experience_bio' => 'خبرة أكثر من عشر سنوات في التدريب والبرمجة والتطوير',
            'facebook_url' => 'https://google.com/notfacebook',
        ];

        $response = $this->postJson('/api/become-instructor', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error', true);
        $response->assertJsonStructure([
            'errors' => [
                'facebook_url',
            ],
        ]);
    }

    public function test_submission_normalizes_facebook_url_without_protocol(): void
    {
        $payload = [
            'first_name' => 'أحمد',
            'last_name' => 'المهندس',
            'email' => 'ahmed.norm@example.com',
            'phone' => '+966501234567',
            'specialty' => 'تطوير الويب',
            'experience_bio' => 'خبرة أكثر من عشر سنوات في التدريب والبرمجة والتطوير',
            'facebook_url' => 'facebook.com/ahmed.norm',
        ];

        $response = $this->postJson('/api/become-instructor', $payload);

        $response->assertOk();
        $this->assertDatabaseHas('instructor_requests', [
            'email' => 'ahmed.norm@example.com',
            'facebook_url' => 'https://facebook.com/ahmed.norm',
        ]);
    }

    public function test_authenticated_user_can_query_their_own_instructor_request(): void
    {
        $user = User::factory()->create([
            'name' => 'سارة الشريف',
            'email' => 'sara@example.com',
        ]);

        $request = InstructorRequest::create([
            'user_id' => $user->id,
            'first_name' => 'سارة',
            'last_name' => 'الشريف',
            'name' => 'سارة الشريف',
            'email' => $user->email,
            'phone' => '+966555123456',
            'job_title' => 'مصممة واجهات تجربة مستخدم',
            'specialty' => 'UI/UX Design',
            'status' => 'under_review',
            'admin_notes' => 'ملاحظات داخلية خاصة بفريق العمل - سري',
            'applicant_feedback' => 'يرجى مراجعة وتحديث نبذة الخبرة',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/my-instructor-request');

        $response->assertOk();
        $response->assertJsonPath('error', false);
        $response->assertJsonPath('data.id', $request->id);
        $response->assertJsonPath('data.status', 'under_review');
        $response->assertJsonPath('data.job_title', 'مصممة واجهات تجربة مستخدم');
        $response->assertJsonPath('data.applicant_feedback', 'يرجى مراجعة وتحديث نبذة الخبرة');
        // Ensure admin_notes is NEVER leaked to applicant endpoint
        $response->assertJsonMissing(['admin_notes']);
    }

    public function test_admin_can_list_filter_and_review_instructor_requests(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleManager::ROLE_SUPER_ADMIN);
        $token = $admin->createToken('admin')->plainTextToken;

        $req1 = InstructorRequest::create([
            'name' => 'خالد علي',
            'email' => 'khaled@example.com',
            'phone' => '+966500000001',
            'specialty' => 'Data Science',
            'status' => 'pending',
            'cv_path' => 'instructors/cvs/khaled.pdf',
        ]);

        $req2 = InstructorRequest::create([
            'name' => 'منى كمال',
            'email' => 'mona@example.com',
            'phone' => '+966500000002',
            'specialty' => 'Cybersecurity',
            'status' => 'approved',
            'intro_video_url' => 'https://youtube.com/watch?v=sample',
        ]);

        $req3 = InstructorRequest::create([
            'name' => 'فهد القحطاني',
            'email' => 'fahad@example.com',
            'phone' => '+966500000003',
            'specialty' => 'Cloud Architecture',
            'status' => 'resubmitted',
        ]);

        // 1. List with status filter
        $response = $this->withToken($token)->getJson('/api/admin/instructor-requests?status=pending');
        $response->assertOk();
        $response->assertJsonPath('data.status_breakdown.pending', 1);
        $response->assertJsonPath('data.status_breakdown.approved', 1);
        $response->assertJsonPath('data.status_breakdown.needs_attention', 2); // req1 (pending) + req3 (resubmitted)
        $this->assertCount(1, $response->json('data.items.data'));

        // 2. Test needs_attention filter (should return req1 and req3)
        $needsAttnResponse = $this->withToken($token)->getJson('/api/admin/instructor-requests?status=needs_attention');
        $needsAttnResponse->assertOk();
        $this->assertCount(2, $needsAttnResponse->json('data.items.data'));

        // 3. Test has_cv filter (req1 has cv)
        $hasCvResponse = $this->withToken($token)->getJson('/api/admin/instructor-requests?has_cv=1');
        $hasCvResponse->assertOk();
        $this->assertCount(1, $hasCvResponse->json('data.items.data'));
        $this->assertSame('خالد علي', $hasCvResponse->json('data.items.data.0.name'));

        // 4. Test has_video filter (req2 has video)
        $hasVideoResponse = $this->withToken($token)->getJson('/api/admin/instructor-requests?has_video=1');
        $hasVideoResponse->assertOk();
        $this->assertCount(1, $hasVideoResponse->json('data.items.data'));
        $this->assertSame('منى كمال', $hasVideoResponse->json('data.items.data.0.name'));

        // 5. Show single request
        $showResponse = $this->withToken($token)->getJson("/api/admin/instructor-requests/{$req1->id}");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.name', 'خالد علي');
        $this->assertArrayHasKey('audit_logs', $showResponse->json('data'));

        // 6. Update status to changes_requested (generates audit log)
        $updateResponse = $this->withToken($token)->putJson("/api/admin/instructor-requests/{$req1->id}/status", [
            'status' => 'changes_requested',
            'admin_notes' => 'ملاحظات داخلية: التحقق من الخبرة في السجل',
            'applicant_feedback' => 'يرجى إرفاق فيديو توضيحي بدقة أعلى وتحديث السيرة الذاتية',
        ]);

        $updateResponse->assertOk();
        $this->assertDatabaseHas('instructor_requests', [
            'id' => $req1->id,
            'status' => 'changes_requested',
            'admin_notes' => 'ملاحظات داخلية: التحقق من الخبرة في السجل',
            'applicant_feedback' => 'يرجى إرفاق فيديو توضيحي بدقة أعلى وتحديث السيرة الذاتية',
            'reviewer_id' => $admin->id,
        ]);

        // Verify audit log exists on show
        $showAfterUpdate = $this->withToken($token)->getJson("/api/admin/instructor-requests/{$req1->id}");
        $showAfterUpdate->assertOk();
        $this->assertNotEmpty($showAfterUpdate->json('data.audit_logs'));

        // 7. Delete request
        $deleteResponse = $this->withToken($token)->deleteJson("/api/admin/instructor-requests/{$req1->id}");
        $deleteResponse->assertOk();
        $this->assertSoftDeleted('instructor_requests', ['id' => $req1->id]);
    }

    public function test_submission_fails_when_facebook_url_is_bare_domain_without_profile_path(): void
    {
        $payload = [
            'first_name' => 'أحمد',
            'last_name' => 'المهندس',
            'email' => 'ahmed.baredomain@example.com',
            'phone' => '+966501234567',
            'specialty' => 'تطوير الويب',
            'experience_bio' => 'خبرة أكثر من عشر سنوات في التدريب والبرمجة والتطوير',
            'facebook_url' => 'https://facebook.com/',
        ];

        $response = $this->postJson('/api/become-instructor', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error', true);
        $response->assertJsonStructure([
            'errors' => [
                'facebook_url',
            ],
        ]);
    }

    public function test_authenticated_applicant_can_resubmit_when_changes_requested_and_files_are_preserved(): void
    {
        $user = User::factory()->create([
            'email' => 'resubmit.applicant@example.com',
        ]);

        $existing = InstructorRequest::create([
            'user_id' => $user->id,
            'name' => 'أحمد القديم',
            'first_name' => 'أحمد',
            'last_name' => 'القديم',
            'email' => $user->email,
            'phone' => '+966501112233',
            'specialty' => 'ذكاء اصطناعي',
            'status' => 'changes_requested',
            'facebook_url' => 'https://facebook.com/old.profile',
            'cv_path' => 'instructor-requests/cv/existing_cv.pdf',
            'cv_original_name' => 'existing_cv.pdf',
            'cv_size' => 12345,
            'applicant_feedback' => 'يرجى تحديث رابط الفيسبوك والنبذة الشخصية',
        ]);

        $resubmitPayload = [
            'first_name' => 'أحمد',
            'last_name' => 'المحدث',
            'email' => $user->email,
            'phone' => '+966501112233',
            'specialty' => 'ذكاء اصطناعي ونظم سحابية',
            'experience_bio' => 'خبرة أكثر من اثني عشر عاماً في التدريب الاحترافي والاستشارات التقنية.',
            'facebook_url' => 'https://facebook.com/ahmed.updated.profile',
            // No new CV uploaded - should retain existing_cv.pdf
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/become-instructor', $resubmitPayload);

        $response->assertOk();
        $response->assertJsonPath('data.request_id', $existing->id);
        $response->assertJsonPath('data.status', 'resubmitted');

        $existing->refresh();
        $this->assertSame('resubmitted', $existing->status);
        $this->assertSame('https://facebook.com/ahmed.updated.profile', $existing->facebook_url);
        $this->assertSame('أحمد المحدث', $existing->name);
        // Preserved previous CV
        $this->assertSame('instructor-requests/cv/existing_cv.pdf', $existing->cv_path);
        $this->assertSame('existing_cv.pdf', $existing->cv_original_name);
    }

    public function test_public_user_can_query_instructor_request_status_by_reference_code(): void
    {
        $req = InstructorRequest::create([
            'first_name' => 'محمد',
            'last_name' => 'خالد',
            'name' => 'محمد خالد',
            'email' => 'mohammed.khaled@example.com',
            'phone' => '+966501239876',
            'specialty' => 'DevOps',
            'status' => 'pending',
            'facebook_url' => 'https://facebook.com/mohammed.khaled',
        ]);

        $refCode = sprintf('EXP-%d-%04d', (int)date('Y'), $req->id);

        $response = $this->getJson('/api/instructor-request/status?reference_code=' . $refCode);
        $response->assertOk();
        $response->assertJsonPath('error', false);
        $response->assertJsonPath('data.id', $req->id);
        $response->assertJsonPath('data.reference_code', $refCode);
        $response->assertJsonPath('data.name', 'محمد خالد');
    }
}

