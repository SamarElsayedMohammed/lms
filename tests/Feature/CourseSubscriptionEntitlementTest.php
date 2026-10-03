<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ContentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseSubscriptionEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ContentAccessService::flushStaticCache();
    }

    public function test_free_course_is_accessible_to_guest_and_user_without_subscription(): void
    {
        $accessService = app(ContentAccessService::class);
        $user = User::factory()->create();

        $freeCourse = Course::factory()->create([
            'course_type' => 'free',
            'is_free' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);

        $this->assertTrue($freeCourse->isFreeNow());
        $this->assertTrue($accessService->canAccessCourse($user, $freeCourse));
    }

    public function test_paid_course_is_locked_for_user_without_subscription(): void
    {
        $accessService = app(ContentAccessService::class);
        $user = User::factory()->create();

        $paidCourse = Course::factory()->create([
            'course_type' => 'paid',
            'is_free' => false,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);

        $this->assertFalse($paidCourse->isFreeNow());
        $this->assertFalse($accessService->canAccessCourse($user, $paidCourse));
    }

    public function test_active_subscription_unlocks_all_published_paid_courses(): void
    {
        $accessService = app(ContentAccessService::class);
        $user = User::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Monthly Pro Plan',
            'slug' => 'monthly-pro-plan',
            'price' => 199.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
            'auto_renew' => true,
        ]);

        $paidCourse1 = Course::factory()->create([
            'course_type' => 'paid',
            'is_free' => false,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);

        $paidCourse2 = Course::factory()->create([
            'course_type' => 'paid',
            'is_free' => false,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);

        $this->assertTrue($accessService->canAccessCourse($user, $paidCourse1));
        $this->assertTrue($accessService->canAccessCourse($user, $paidCourse2));
    }

    public function test_expired_subscription_revokes_access_to_paid_courses(): void
    {
        $accessService = app(ContentAccessService::class);
        $user = User::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Monthly Plan Expired',
            'slug' => 'monthly-plan-expired',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        // Subscription that expired yesterday
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subDay(),
            'auto_renew' => false,
        ]);

        $paidCourse = Course::factory()->create([
            'course_type' => 'paid',
            'is_free' => false,
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);

        $this->assertFalse($accessService->canAccessCourse($user, $paidCourse));
    }

    public function test_admin_can_create_and_update_paid_course_without_price_or_package(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $category = Category::create([
            'name' => 'Programming',
            'slug' => 'programming-' . uniqid(),
            'is_active' => true,
        ]);

        $createPayload = [
            'title' => 'New Premium Subscription Course',
            'category_id' => $category->id,
            'short_description' => 'A great paid course for subscribers',
            'is_free' => false,
            'course_type' => 'paid',
            'status' => 'publish',
        ];

        // 1. Create paid course with no package and no price
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/courses', $createPayload)
            ->assertStatus(201);

        $courseId = $response->json('data.id');
        $this->assertNotNull($courseId);

        $this->assertDatabaseHas('courses', [
            'id' => $courseId,
            'is_free' => 0,
            'course_type' => 'paid',
            'status' => 'publish',
            'approval_status' => 'approved',
            'is_active' => 1,
        ]);

        // 2. Update paid course with no package and no price
        $updatePayload = [
            'title' => 'Updated Premium Subscription Course',
            'category_id' => $category->id,
            'is_free' => false,
            'course_type' => 'paid',
        ];

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/courses/{$courseId}/update", $updatePayload)
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated Premium Subscription Course')
            ->assertJsonPath('data.is_free', false)
            ->assertJsonPath('data.course_type', 'paid');
    }

    public function test_admin_can_create_free_course_without_package(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $category = Category::create([
            'name' => 'General',
            'slug' => 'general-' . uniqid(),
            'is_active' => true,
        ]);

        $createPayload = [
            'title' => 'Community Free Course',
            'category_id' => $category->id,
            'short_description' => 'A completely free course for all learners',
            'is_free' => true,
            'course_type' => 'free',
            'status' => 'publish',
        ];

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/courses', $createPayload)
            ->assertStatus(201);

        $courseId = $response->json('data.id');
        $this->assertNotNull($courseId);

        $this->assertDatabaseHas('courses', [
            'id' => $courseId,
            'is_free' => 1,
            'course_type' => 'free',
            'status' => 'publish',
        ]);
    }
}
