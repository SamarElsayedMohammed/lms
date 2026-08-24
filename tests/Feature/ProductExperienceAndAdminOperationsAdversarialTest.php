<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Lecture\CourseLectureNote;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductExperienceAndAdminOperationsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'courses-edit', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'courses-delete', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'course-chapters-edit', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance-edit', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'finance-list', 'guard_name' => 'web']);

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $supervisorRole = Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web']);
        $instructorRole = Role::firstOrCreate(['name' => 'Instructor', 'guard_name' => 'web']);
        $studentRole = Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web']);

        $superAdminRole->syncPermissions(Permission::all());
        $adminRole->syncPermissions(['courses-edit', 'courses-delete', 'course-chapters-edit', 'finance-edit', 'finance-list']);
        $instructorRole->syncPermissions(['course-chapters-edit']);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * @test
     * A2: Lecture Note timestamp bounds & BOLA ownership verification
     */
    public function test_lecture_note_bounds_and_ownership_enforcement(): void
    {
        $this->withoutExceptionHandling();
        $student1 = User::factory()->create();
        $student2 = User::factory()->create();

        $instructor = User::factory()->create();

        $course = Course::factory()->create([
            'user_id'         => $instructor->id,
            'status'          => 'publish',
            'is_active'       => 1,
            'approval_status' => 'approved',
            'course_type'     => 'paid',
        ]);

        $chapter = CourseChapter::create([
            'course_id'     => $course->id,
            'user_id'       => $instructor->id,
            'title'         => 'Chapter 1',
            'slug'          => 'chapter-1-' . uniqid(),
            'is_active'     => true,
            'chapter_order' => 1,
        ]);

        $lecture = CourseChapterLecture::create([
            'user_id'           => $instructor->id,
            'course_chapter_id' => $chapter->id,
            'title'             => 'Lecture 1',
            'slug'              => 'lecture-1-' . uniqid(),
            'type'              => 'url',
            'duration_seconds'  => 300, // 5 minutes
            'is_active'         => true,
            'free_preview'      => true,
            'is_free'           => true,
            'chapter_order'     => 1,
        ]);

        // 1. Out of bounds timestamp (> 300s) -> Should be clamped to duration_seconds (300)
        $res = $this->actingAs($student1, 'sanctum')->postJson("/api/lecture/{$lecture->id}/notes", [
            'video_timestamp_seconds' => 9999,
            'note_text'               => 'Out of bounds note',
        ]);
        $res->assertStatus(201);
        $note = CourseLectureNote::where('user_id', $student1->id)->first();
        $this->assertNotNull($note);
        $this->assertEquals(300, $note->video_timestamp_seconds, 'Timestamp should be clamped to duration_seconds');

        // 2. Student2 cannot update Student1's note (BOLA / IDOR protection)
        $res = $this->actingAs($student2, 'sanctum')->putJson("/api/lecture/notes/{$note->id}", [
            'note_text' => 'Hacked text',
        ]);
        $res->assertStatus(403);
        $this->assertEquals('Out of bounds note', $note->fresh()->note_text);

        // 3. Student2 cannot delete Student1's note
        $res = $this->actingAs($student2, 'sanctum')->deleteJson("/api/lecture/notes/{$note->id}");
        $res->assertStatus(403);
        $this->assertDatabaseHas('course_lecture_notes', ['id' => $note->id]);

        // 4. Student1 can update their own note
        $res = $this->actingAs($student1, 'sanctum')->putJson("/api/lecture/notes/{$note->id}", [
            'video_timestamp_seconds' => 120,
            'note_text'               => 'Updated legitimate note',
        ]);
        $res->assertStatus(200);
        $this->assertEquals('Updated legitimate note', $note->fresh()->note_text);
        $this->assertEquals(120, $note->fresh()->video_timestamp_seconds);
    }

    /**
     * @test
     * B7: Admin Audit Logs immutable storage, secret redaction, and access restriction
     */
    public function test_audit_logs_redacts_credentials_and_restricts_access(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $student = User::factory()->create();
        $student->assignRole('Student');

        // Execute an audit log entry containing raw sensitive credentials
        AuditLogService::log(
            action: 'course_approved',
            summary: 'Admin approved course with sensitive config',
            details: [
                'course_id'     => 42,
                'api_token'     => 'super_secret_bearer_token_xyz',
                'password'      => 'PlainTextPassword123!',
                'cvv'           => '999',
                'fcm_token'     => 'firebase_token_payload_123',
                'public_data'   => 'visible_value',
            ]
        );

        $log = AdminAuditLog::latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('course_approved', $log->action);
        $this->assertEquals('[REDACTED]', $log->details['api_token']);
        $this->assertEquals('[REDACTED]', $log->details['password']);
        $this->assertEquals('[REDACTED]', $log->details['cvv']);
        $this->assertEquals('[REDACTED]', $log->details['fcm_token']);
        $this->assertEquals('visible_value', $log->details['public_data']);

        // Non-admin (Student) cannot access audit logs
        $res = $this->actingAs($student, 'sanctum')->getJson('/api/admin/audit-logs');
        $res->assertStatus(403);

        // Super Admin can access audit logs
        $res = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/admin/audit-logs');
        $res->assertStatus(200);
        $this->assertTrue($res->json('status'));
    }

    /**
     * @test
     * B3: Bulk Course status update safely persists and logs audit
     */
    public function test_courses_bulk_status_update(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $course1 = Course::factory()->create(['status' => 'draft', 'is_active' => 0]);
        $course2 = Course::factory()->create(['status' => 'pending', 'is_active' => 0]);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/courses/bulk-status', [
            'ids'    => [$course1->id, $course2->id],
            'status' => 'publish',
        ]);

        $res->assertStatus(200);
        $this->assertEquals('publish', $course1->fresh()->status);
        $this->assertEquals('approved', $course1->fresh()->approval_status);
        $this->assertEquals(1, $course1->fresh()->is_active);

        $this->assertEquals('publish', $course2->fresh()->status);
        $this->assertEquals('approved', $course2->fresh()->approval_status);
        $this->assertEquals(1, $course2->fresh()->is_active);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'courses_bulk_status_update',
        ]);
    }

    public function test_approving_a_course_publishes_it_for_the_catalog(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $course = Course::factory()->create([
            'status' => 'pending',
            'approval_status' => null,
            'is_active' => false,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/courses/{$course->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'publish')
            ->assertJsonPath('data.approval_status', 'approved')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => 1,
        ]);
    }

    public function test_course_learning_outcomes_and_requirements_are_replaced_and_persisted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $course = Course::factory()->create();

        $update = fn (array $payload) => $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/courses/{$course->id}/update", array_merge([
                'title' => $course->title,
                'category_id' => $course->category_id,
            ], $payload));

        $update([
            'learnings' => [['title' => 'Build a complete project'], ['title' => 'Apply the core concepts']],
            'requirements' => [['requirement' => 'A laptop'], ['requirement' => 'Basic knowledge']],
            'replace_learnings' => true,
            'replace_requirements' => true,
            'ai_knowledge_content' => 'Course-specific assistant context',
            'is_free_until' => '2030-01-01 00:00:00',
            'total_duration_override_seconds' => 5400,
            'total_lessons_override' => 12,
        ])->assertOk()
            ->assertJsonCount(2, 'data.learnings')
            ->assertJsonCount(2, 'data.requirements');

        $this->assertDatabaseHas('course_learnings', ['course_id' => $course->id, 'title' => 'Build a complete project']);
        $this->assertDatabaseHas('course_requirements', ['course_id' => $course->id, 'requirement' => 'A laptop']);
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'ai_knowledge_content' => 'Course-specific assistant context',
            'duration_seconds' => 5400,
            'lectures_count' => 12,
        ]);

        $update([
            'learnings' => [],
            'requirements' => [],
            'replace_learnings' => true,
            'replace_requirements' => true,
        ])->assertOk()
            ->assertJsonCount(0, 'data.learnings')
            ->assertJsonCount(0, 'data.requirements');

        $this->assertDatabaseMissing('course_learnings', ['course_id' => $course->id]);
        $this->assertDatabaseMissing('course_requirements', ['course_id' => $course->id]);
    }

    public function test_course_details_editor_persists_only_the_requested_collection_for_an_authorized_editor(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $course = Course::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/courses/{$course->id}/learning-details", [
                'learnings' => ['Understand the workflow'],
            ])
            ->assertOk()
            ->assertJsonPath('data.learnings.0', 'Understand the workflow')
            ->assertJsonPath('data.requirements', []);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/courses/{$course->id}/learning-details", [
                'requirements' => ['A working browser'],
            ])
            ->assertOk()
            ->assertJsonPath('data.learnings.0', 'Understand the workflow')
            ->assertJsonPath('data.requirements.0', 'A working browser');

        $student = User::factory()->create();
        $this->actingAs($student, 'sanctum')
            ->putJson("/api/courses/{$course->id}/learning-details", [
                'learnings' => ['Unauthorized change'],
            ])
            ->assertForbidden();
    }

    /**
     * @test
     * B4: Curriculum reordering atomic transaction & foreign ID rejection
     */
    public function test_curriculum_reordering_foreign_id_rejection(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $instructor = User::factory()->create();
        $instructor->assignRole('Instructor');
        $instructor->givePermissionTo('course-chapters-edit');
        \App\Models\Instructor::create(['user_id' => $instructor->id, 'status' => 'approved', 'type' => 'individual']);

        $courseA = Course::factory()->create(['user_id' => $instructor->id]);
        $chapterA = CourseChapter::create([
            'course_id'     => $courseA->id,
            'user_id'       => $instructor->id,
            'title'         => 'Chapter A',
            'slug'          => 'chapter-a-' . uniqid(),
            'is_active'     => true,
            'chapter_order' => 1,
        ]);
        $lectureA1 = CourseChapterLecture::create([
            'user_id'           => $instructor->id,
            'course_chapter_id' => $chapterA->id,
            'title'             => 'Lecture A1',
            'slug'              => 'lecture-a1-' . uniqid(),
            'type'              => 'url',
            'is_active'         => true,
            'chapter_order'     => 1,
        ]);
        $lectureA2 = CourseChapterLecture::create([
            'user_id'           => $instructor->id,
            'course_chapter_id' => $chapterA->id,
            'title'             => 'Lecture A2',
            'slug'              => 'lecture-a2-' . uniqid(),
            'type'              => 'url',
            'is_active'         => true,
            'chapter_order'     => 2,
        ]);

        $courseB = Course::factory()->create(['user_id' => $instructor->id]);
        $chapterB = CourseChapter::create([
            'course_id'     => $courseB->id,
            'user_id'       => $instructor->id,
            'title'         => 'Chapter B',
            'slug'          => 'chapter-b-' . uniqid(),
            'is_active'     => true,
            'chapter_order' => 1,
        ]);
        $lectureB1 = CourseChapterLecture::create([
            'user_id'           => $instructor->id,
            'course_chapter_id' => $chapterB->id,
            'title'             => 'Lecture B1',
            'slug'              => 'lecture-b1-' . uniqid(),
            'type'              => 'url',
            'is_active'         => true,
            'chapter_order'     => 1,
        ]);

        // Attempt to inject foreign lecture (from Course/Chapter B) into Chapter A reorder
        $res = $this->actingAs($instructor, 'web')->postJson("/api/course-chapters/{$chapterA->id}/curriculum/reorder", [
            'ids' => [$lectureA2->id, $lectureB1->id],
        ]);

        // Foreign ID injection should fail
        $this->assertTrue($res->json('error') ?? false);

        // Legitimate reordering within Chapter A
        $res = $this->actingAs($instructor, 'web')->postJson("/api/course-chapters/{$chapterA->id}/curriculum/reorder", [
            'ids' => [$lectureA2->id, $lectureA1->id],
        ]);

        $res->assertSuccessful();
        $this->assertFalse($res->json('error') ?? true);
        $this->assertEquals(1, $lectureA2->fresh()->chapter_order);
        $this->assertEquals(2, $lectureA1->fresh()->chapter_order);
    }

    /**
     * @test
     * B6: Subscription Expiry Manual Reminder with renewal suppression
     */
    public function test_subscription_expiry_manual_reminder(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $student = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'name'          => 'Pro Plan',
            'slug'          => 'pro-plan-' . uniqid(),
            'price'         => 199.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active'     => true,
        ]);

        $subscription = Subscription::create([
            'user_id'   => $student->id,
            'plan_id'   => $plan->id,
            'status'    => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(25),
            'ends_at'   => now()->addDays(5),
            'currency'  => 'EGP',
            'amount'    => 199.00,
        ]);

        $res = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/subscriptions/{$subscription->id}/remind-expiry");
        $res->assertStatus(200);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'subscription_manual_expiry_reminder_sent',
        ]);

        // Suppress reminder if queued renewal exists
        Subscription::create([
            'user_id'   => $student->id,
            'plan_id'   => $plan->id,
            'status'    => Subscription::STATUS_PENDING,
            'starts_at' => now()->addDays(5),
            'ends_at'   => now()->addDays(35),
            'currency'  => 'EGP',
            'amount'    => 199.00,
        ]);

        $res = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/subscriptions/{$subscription->id}/remind-expiry");
        $res->assertStatus(400);
    }
}
