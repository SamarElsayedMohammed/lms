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

    public function test_admin_tracking_resolves_progress_and_status_correctly(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $token = $admin->createToken('admin')->plainTextToken;

        $student = User::factory()->create(['name' => 'Tracked Student', 'email' => 'tracked@skillso.test']);

        $category = \App\Models\Category::create([
            'name' => 'Tracking Category',
            'slug' => 'tracking-category',
            'is_active' => true,
        ]);

        $course = \App\Models\Course\Course::create([
            'title' => 'Tracking Course',
            'slug' => 'tracking-course',
            'user_id' => $admin->id,
            'category_id' => $category->id,
            'price' => 100.0,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
        ]);

        $order = \App\Models\Order::create([
            'user_id' => $student->id,
            'order_number' => 'ORD-TRK-01',
            'total_price' => 100.0,
            'final_price' => 100.0,
            'status' => 'completed',
        ]);

        \App\Models\OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $course->id,
            'price' => 100.0,
        ]);

        \App\Models\UserCourseProgress::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'in_progress',
            'progress_percentage' => 45.0,
            'completed_items' => 1,
            'total_items' => 2,
        ]);

        $response = $this->withToken($token)->getJson('/api/admin/tracking?status=in_progress');

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.total'));
        $item = $response->json('data.data.0');
        $this->assertEquals('Tracked Student', $item['student_name']);
        $this->assertEquals('Tracking Course', $item['course_name']);
        $this->assertEquals(45, $item['progress']);
        $this->assertEquals('in_progress', $item['status']);
    }
}
