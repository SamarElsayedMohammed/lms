<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\CourseInstructor;
use App\Models\Instructor;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorCourseOwnershipReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $owner;
    private User $assignedOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'instructors-list', 'guard_name' => 'web']);
        $role->givePermissionTo('instructors-list');

        $this->admin = User::factory()->create(['email' => 'admin-instructor-own@skillso.test']);
        $this->admin->assignRole($role);

        $this->owner = User::factory()->create(['name' => 'Owner Instructor', 'email' => 'owner-inst@skillso.test']);
        Instructor::create(['user_id' => $this->owner->id, 'status' => 'approved', 'type' => 'individual']);

        $this->assignedOnly = User::factory()->create(['name' => 'Assigned Instructor', 'email' => 'assigned-inst@skillso.test']);
        Instructor::create(['user_id' => $this->assignedOnly->id, 'status' => 'approved', 'type' => 'individual']);

        $category = Category::create(['name' => 'Ownership', 'slug' => 'ownership', 'is_active' => true]);
        $ownedA = Course::factory()->create([
            'title' => 'Owned A',
            'user_id' => $this->owner->id,
            'category_id' => $category->id,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
        ]);
        Course::factory()->create([
            'title' => 'Owned Draft',
            'user_id' => $this->owner->id,
            'category_id' => $category->id,
            'is_active' => false,
            'status' => 'draft',
            'approval_status' => 'pending',
        ]);
        $assigned = Course::factory()->create([
            'title' => 'Assigned Course',
            'user_id' => $this->admin->id,
            'category_id' => $category->id,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
        ]);

        CourseInstructor::create(['course_id' => $ownedA->id, 'user_id' => $this->owner->id, 'is_active' => true]);
        CourseInstructor::create(['course_id' => $assigned->id, 'user_id' => $this->owner->id, 'is_active' => true]);
        CourseInstructor::create(['course_id' => $assigned->id, 'user_id' => $this->assignedOnly->id, 'is_active' => true]);
    }

    public function test_report_and_management_use_the_same_owned_plus_assigned_union(): void
    {
        $report = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/instructor?report_type=detailed');
        $management = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/instructors');

        $report->assertOk();
        $management->assertOk();

        $reportRows = collect($report->json('data.data') ?? $report->json('data'));
        $ownerReport = $reportRows->first(fn ($row) => (int) ($row['user_id'] ?? 0) === $this->owner->id);
        $assignedReport = $reportRows->first(fn ($row) => (int) ($row['user_id'] ?? 0) === $this->assignedOnly->id);

        $this->assertNotNull($ownerReport);
        $this->assertSame(3, (int) $ownerReport['courses_count']);
        $this->assertSame(2, (int) $ownerReport['owned_courses_count']);
        $this->assertSame(2, (int) $ownerReport['assigned_courses_count']);
        $this->assertSame(1, (int) $assignedReport['courses_count']);

        $managementRows = collect($management->json('data.data') ?? $management->json('data'));
        $ownerManagement = $managementRows->first(fn ($row) => (int) ($row['id'] ?? 0) === $this->owner->id);
        $assignedManagement = $managementRows->first(fn ($row) => (int) ($row['id'] ?? 0) === $this->assignedOnly->id);

        $this->assertSame((int) $ownerReport['courses_count'], (int) $ownerManagement['courses_count']);
        $this->assertSame((int) $assignedReport['courses_count'], (int) $assignedManagement['courses_count']);
    }

    public function test_top_performers_require_students_or_ratings(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/instructor');

        $response->assertOk();
        $this->assertSame([], $response->json('data.top_instructors_by_courses'));
        $this->assertTrue($response->json('data.ranking_insufficient_data'));
    }

    public function test_students_and_ratings_use_owned_plus_assigned_union_courses(): void
    {
        $buyerOwned = User::factory()->create(['email' => 'buyer-owned@skillso.test']);
        $buyerAssigned = User::factory()->create(['email' => 'buyer-assigned@skillso.test']);
        $ownedCourse = Course::query()->where('title', 'Owned A')->firstOrFail();
        $assignedCourse = Course::query()->where('title', 'Assigned Course')->firstOrFail();

        $this->createCompletedPurchase($buyerOwned, $ownedCourse, 80);
        $this->createCompletedPurchase($buyerAssigned, $assignedCourse, 120);

        Rating::query()->create([
            'user_id' => $buyerOwned->id,
            'rateable_id' => $ownedCourse->id,
            'rateable_type' => Course::class,
            'rating' => 4,
            'status' => 'approved',
        ]);

        $detailed = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/instructor?report_type=detailed');
        $summary = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/instructor');

        $detailed->assertOk();
        $summary->assertOk();

        $rows = collect($detailed->json('data.data') ?? $detailed->json('data'));
        $ownerRow = $rows->first(fn ($row) => (int) ($row['user_id'] ?? 0) === $this->owner->id);
        $assignedRow = $rows->first(fn ($row) => (int) ($row['user_id'] ?? 0) === $this->assignedOnly->id);

        $this->assertSame(2, (int) $ownerRow['students_count']);
        $this->assertSame(4.0, (float) $ownerRow['average_rating']);
        $this->assertSame(1, (int) $assignedRow['students_count']);
        $this->assertNull($assignedRow['average_rating']);

        $this->assertSame(2, (int) $summary->json('data.total_students'));
        $this->assertFalse((bool) $summary->json('data.ranking_insufficient_data'));
        $top = collect($summary->json('data.top_instructors_by_courses'));
        $this->assertTrue($top->contains(fn ($row) => (int) ($row['user_id'] ?? 0) === $this->owner->id));
    }

    private function createCompletedPurchase(User $buyer, Course $course, float $amount): void
    {
        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => 'ORD-INST-' . $buyer->id . '-' . $course->id,
            'total_price' => $amount,
            'final_price' => $amount,
            'amount_egp' => $amount,
            'exchange_rate_snapshot' => 1,
            'payment_method' => 'card',
            'status' => 'completed',
        ]);
        OrderCourse::create([
            'order_id' => $order->id,
            'course_id' => $course->id,
            'price' => $amount,
            'tax_price' => 0,
            'amount_egp' => $amount,
            'exchange_rate_snapshot' => 1,
        ]);
    }
}
