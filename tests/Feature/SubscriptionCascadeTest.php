<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionCascadeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_cancels_queued_child_subscriptions_when_parent_is_cancelled()
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::factory()->create();

        // Active parent subscription
        $parent = Subscription::factory()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        // Queued child subscription
        $child = Subscription::factory()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'parent_subscription_id' => $parent->id,
        ]);

        // Unrelated queued subscription
        $unrelated = Subscription::factory()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_PENDING,
            'parent_subscription_id' => null,
        ]);

        $service = app(SubscriptionService::class);
        $service->cancelSubscription($parent, 'User requested cancellation');

        // Assert parent is cancelled
        $this->assertEquals(Subscription::STATUS_CANCELLED, $parent->fresh()->status);
        $this->assertStringContainsString('User requested cancellation', $parent->fresh()->cancellation_reason);

        // Assert child is cancelled (Bug 13 Fix verified)
        $this->assertEquals(Subscription::STATUS_CANCELLED, $child->fresh()->status);
        $this->assertStringContainsString('Parent subscription was cancelled', $child->fresh()->cancellation_reason);

        // Assert unrelated is NOT cancelled
        $this->assertEquals(Subscription::STATUS_PENDING, $unrelated->fresh()->status);
    }
}
