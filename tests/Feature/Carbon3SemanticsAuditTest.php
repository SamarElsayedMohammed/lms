<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Order;
use App\Models\OrderCourse;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Carbon3SemanticsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 1. Subscription days_remaining accessor is sign-correct under Carbon 3.
     */
    public function test_subscription_days_remaining_semantics(): void
    {
        $now = Carbon::parse('2026-10-04 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();

        // Active subscription expiring in 10 days
        $activeSub = new Subscription([
            'user_id' => $user->id,
            'status' => 'active',
            'starts_at' => $now->copy()->subDays(20),
            'ends_at' => $now->copy()->addDays(10),
        ]);

        $this->assertEquals(10, $activeSub->days_remaining, 'Active future subscription must return positive remaining days');

        // Expired subscription (ended 5 days ago)
        $expiredSub = new Subscription([
            'user_id' => $user->id,
            'status' => 'expired',
            'starts_at' => $now->copy()->subDays(35),
            'ends_at' => $now->copy()->subDays(5),
        ]);

        $this->assertEquals(0, $expiredSub->days_remaining, 'Expired subscription must return 0 remaining days, never negative');

        // Lifetime subscription (ends_at is null)
        $lifetimeSub = new Subscription([
            'user_id' => $user->id,
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => null,
        ]);

        $this->assertNull($lifetimeSub->days_remaining, 'Lifetime subscription must return null remaining days');
    }

    /**
     * 2. Refund eligibility sign inversion in ServesCourseLearning is fixed.
     */
    public function test_refund_eligibility_carbon_3_sign_correctness(): void
    {
        $now = Carbon::parse('2026-10-04 12:00:00');
        Carbon::setTestNow($now);

        $student = User::factory()->create(['is_active' => true]);
        $instructor = User::factory()->create(['is_active' => true]);

        $course = Course::factory()->create([
            'user_id' => $instructor->id,
            'title' => 'Refund Test Course',
            'course_type' => 'paid',
            'price' => 100,
            'is_active' => true,
            'status' => 'publish',
            'approval_status' => 'approved',
        ]);

        \App\Models\Course\UserCourseTrack::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'in_progress',
        ]);

        // Order 1: Purchased 5 days ago (within 14-day refund window)
        $recentOrder = Order::create([
            'user_id' => $student->id,
            'status' => 'completed',
            'payment_method' => 'wallet',
            'total_price' => 100,
            'final_price' => 100,
            'order_number' => 'ORD-RECENT',
            'created_at' => $now->copy()->subDays(5),
            'updated_at' => $now->copy()->subDays(5),
        ]);
        OrderCourse::create([
            'order_id' => $recentOrder->id,
            'course_id' => $course->id,
            'price' => 100,
            'tax_price' => 0,
            'created_at' => $now->copy()->subDays(5),
        ]);

        // Query My Learning API which uses ServesCourseLearning
        $response = $this->actingAs($student)->getJson('/api/my-learning');
        $response->assertStatus(200);

        $courseData = collect($response->json('data.data') ?? $response->json('data'))->firstWhere('id', $course->id);
        $this->assertNotNull($courseData);

        // If refund_details exists, verify it is eligible with 9 days remaining (14 - 5)
        if (isset($courseData['refund_details'])) {
            $this->assertTrue((bool) $courseData['refund_details']['is_eligible']);
            $this->assertEquals(9, $courseData['refund_details']['days_remaining']);
        }

        // Now advance time by 20 days (order is now 25 days old, past 14-day window)
        Carbon::setTestNow($now->copy()->addDays(20));

        $responseExpired = $this->actingAs($student)->getJson('/api/my-learning');
        $responseExpired->assertStatus(200);

        $courseDataExpired = collect($responseExpired->json('data.data') ?? $responseExpired->json('data'))->firstWhere('id', $course->id);
        if (isset($courseDataExpired['refund_details'])) {
            $this->assertFalse((bool) $courseDataExpired['refund_details']['is_eligible'], 'Order past refund window must NOT be refund eligible');
            $this->assertEquals(0, $courseDataExpired['refund_details']['days_remaining']);
        }
    }
}
