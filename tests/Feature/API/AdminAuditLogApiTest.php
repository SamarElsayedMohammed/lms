<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Supervisor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    public function test_student_cannot_read_audit_logs(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/audit-logs')
            ->assertStatus(403);
    }

    public function test_staff_cannot_read_audit_logs(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('Staff');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/admin/audit-logs')
            ->assertStatus(403);
    }

    public function test_supervisor_receives_laravel_paginated_envelope(): void
    {
        $supervisor = User::factory()->create(['name' => 'مشرف', 'email' => 'supervisor@skillso.test']);
        $supervisor->assignRole('Supervisor');

        AdminAuditLog::create([
            'user_id'     => $supervisor->id,
            'actor_name'  => $supervisor->name,
            'actor_email' => $supervisor->email,
            'action'      => 'course_approved',
            'target_type' => 'Course',
            'target_id'   => 44,
            'summary'     => 'Approved course #44',
            'details'     => ['course_id' => 44],
            'ip_address'  => '127.0.0.1',
            'created_at'  => now(),
        ]);

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/admin/audit-logs?per_page=20');

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'data',
                ],
            ]);

        $this->assertSame('course_approved', $response->json('data.data.0.action'));
        $this->assertSame('مشرف', $response->json('data.data.0.actor_name'));
    }

    public function test_action_and_date_filters_are_applied(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        AdminAuditLog::create([
            'user_id'     => $admin->id,
            'actor_name'  => $admin->name,
            'actor_email' => $admin->email,
            'action'      => 'course_approved',
            'summary'     => 'Approved',
            'created_at'  => now()->subDay(),
        ]);
        AdminAuditLog::create([
            'user_id'     => $admin->id,
            'actor_name'  => $admin->name,
            'actor_email' => $admin->email,
            'action'      => 'promo_code_created',
            'summary'     => 'Created promo',
            'created_at'  => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/audit-logs?action=promo_code_created&from_date=' . now()->toDateString());

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('promo_code_created', $response->json('data.data.0.action'));
    }

    public function test_toggling_user_status_writes_an_audit_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $target = User::factory()->create(['is_active' => 1]);

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'users-list', 'guard_name' => 'web']);
        $admin->givePermissionTo('users-list');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$target->id}/toggle-status")
            ->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'action'     => 'user_status_toggled',
            'target_id'  => $target->id,
            'target_type'=> 'User',
        ]);
    }

    public function test_sensitive_keys_including_app_key_are_redacted(): void
    {
        AuditLogService::log(
            action: 'settings_updated',
            summary: 'Updated settings',
            details: [
                'APP_KEY'  => 'base64:should-not-leak',
                'app_key'  => 'also-secret',
                'password' => 'PlainText',
                'safe'     => 'visible',
            ]
        );

        $log = AdminAuditLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('[REDACTED]', $log->details['APP_KEY']);
        $this->assertSame('[REDACTED]', $log->details['app_key']);
        $this->assertSame('[REDACTED]', $log->details['password']);
        $this->assertSame('visible', $log->details['safe']);
    }

    public function test_admin_login_writes_an_audit_log(): void
    {
        $admin = User::factory()->create([
            'email' => 'audit-login@skillso.test',
            'password' => bcrypt('Secret123!'),
        ]);
        $admin->assignRole('Super Admin');

        $this->postJson('/api/admin-login', [
            'email' => 'audit-login@skillso.test',
            'password' => 'Secret123!',
        ])->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'admin_login',
            'user_id' => $admin->id,
        ]);
    }

    public function test_updating_a_user_writes_an_audit_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'users-list', 'guard_name' => 'web']);
        $admin->givePermissionTo('users-list');

        $target = User::factory()->create(['name' => 'قبل التعديل']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}", [
                'name' => 'بعد التعديل',
            ])
            ->assertOk();

        $this->assertTrue(
            AdminAuditLog::query()->where('user_id', $admin->id)->exists(),
            'Expected an admin audit row for user update',
        );
    }
}
