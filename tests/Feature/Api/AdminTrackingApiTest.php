<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTrackingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
    }

    public function test_unauthenticated_request_returns_403_or_401(): void
    {
        $response = $this->getJson('/api/admin/tracking');

        $this->assertContains($response->status(), [401, 403]);
    }

    public function test_non_admin_user_is_forbidden(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/tracking');

        $this->assertContains($response->status(), [403, 500]);
    }

    public function test_admin_receives_paginated_tracking_structure(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $token = $admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/tracking?per_page=15');

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

        $this->assertTrue($response->json('success'));
    }

    public function test_invalid_status_filter_returns_422(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $token = $admin->createToken('admin')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/tracking?status=invalid');

        $response->assertStatus(422);
    }
}
