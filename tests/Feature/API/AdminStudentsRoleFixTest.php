<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\RoleManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminStudentsRoleFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RoleManager::ensureCanonicalRolesExist();
    }

    public function test_canonical_student_role_exists_with_expected_guard(): void
    {
        $role = Role::where('name', RoleManager::ROLE_STUDENT)->where('guard_name', 'web')->first();
        $this->assertNotNull($role);
        $this->assertEquals('web', $role->guard_name);
    }

    public function test_student_registration_assigns_correct_role(): void
    {
        $student = User::factory()->create();
        $student->assignRole(RoleManager::ROLE_STUDENT);

        $this->assertTrue($student->hasRole(RoleManager::ROLE_STUDENT));
    }

    public function test_students_stats_returns_200_ok(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleManager::ROLE_SUPER_ADMIN);
        $token = $admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/users/stats?role=student');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['total', 'active', 'inactive', 'role_count'],
            ]);
    }

    public function test_students_list_returns_200_with_pagination(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleManager::ROLE_SUPER_ADMIN);
        $token = $admin->createToken('admin')->plainTextToken;

        User::factory()->count(3)->create()->each(function ($user) {
            $user->assignRole(RoleManager::ROLE_STUDENT);
        });

        $response = $this->withToken($token)->getJson('/api/admin/users?role=student&per_page=10');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'data',
                ],
            ]);
    }

    public function test_admin_list_filters_before_pagination_and_keeps_the_role_query(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(RoleManager::ROLE_SUPER_ADMIN);
        $token = $superAdmin->createToken('admin')->plainTextToken;

        $admin = User::factory()->create();
        $admin->assignRole(RoleManager::ROLE_ADMIN);
        $student = User::factory()->create();
        $student->assignRole(RoleManager::ROLE_STUDENT);

        $response = $this->withToken($token)->getJson('/api/admin/users?role=admin&per_page=10');

        $response->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->assertCount(2, $response->json('data.data'));
        $this->assertNotContains($student->id, collect($response->json('data.data'))->pluck('id')->all());
        $this->assertStringContainsString('role=admin', (string) $response->json('data.first_page_url'));
    }

    public function test_zero_students_returns_valid_zero_totals(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleManager::ROLE_SUPER_ADMIN);
        $token = $admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/users/stats?role=student');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_active_and_suspended_counts_are_correct(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleManager::ROLE_SUPER_ADMIN);
        $token = $admin->createToken('admin')->plainTextToken;

        $activeStudent = User::factory()->create(['is_active' => true]);
        $activeStudent->assignRole(RoleManager::ROLE_STUDENT);

        $inactiveStudent = User::factory()->create(['is_active' => false]);
        $inactiveStudent->assignRole(RoleManager::ROLE_STUDENT);

        $response = $this->withToken($token)->getJson('/api/admin/users/stats?role=student');

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.total'));
        $this->assertEquals(1, $response->json('data.active'));
        $this->assertEquals(1, $response->json('data.inactive'));
    }

    public function test_missing_or_misconfigured_role_produces_controlled_result_not_uncaught_exception(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleManager::ROLE_SUPER_ADMIN);
        $token = $admin->createToken('admin')->plainTextToken;

        // Query an unknown non-existent role string
        $response = $this->withToken($token)->getJson('/api/admin/users/stats?role=non_existent_role_xyz');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_admin_authorization_is_enforced(): void
    {
        $response = $this->getJson('/api/admin/users/stats?role=student');
        $this->assertContains($response->status(), [401, 403]);
    }

    public function test_normal_student_cannot_access_admin_student_endpoints(): void
    {
        $student = User::factory()->create();
        $student->assignRole(RoleManager::ROLE_STUDENT);
        $token = $student->createToken('student')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/users/stats?role=student');
        $this->assertContains($response->status(), [401, 403]);
    }

    public function test_role_seeders_can_run_repeatedly_without_duplicates(): void
    {
        $seeder = new RolePermissionSeeder();
        $seeder->run();
        $seeder->run();

        $studentCount = Role::where('name', RoleManager::ROLE_STUDENT)->where('guard_name', 'web')->count();
        $this->assertEquals(1, $studentCount);
    }
}
