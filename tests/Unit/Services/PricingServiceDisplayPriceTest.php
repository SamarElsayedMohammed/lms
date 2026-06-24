<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PricingServiceDisplayPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_price_is_rounded_up_to_nearest_whole_number(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan',
            'price' => 999.99,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        SubscriptionPlanPrice::create([
            'plan_id' => $plan->id,
            'country_code' => 'EG',
            'currency_code' => 'EGP',
            'price' => 149.25,
            'old_price' => 199.01,
            'is_active' => true,
            'can_subscribe' => true,
        ]);

        $pricing = app(PricingService::class)->getPriceForCountry($plan, 'EG');

        $this->assertSame(150, $pricing['price']);
        $this->assertSame(200, $pricing['old_price']);
    }

    public function test_whole_number_prices_remain_unchanged(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Whole Plan',
            'slug' => 'whole-plan',
            'price' => 800,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        SubscriptionPlanPrice::create([
            'plan_id' => $plan->id,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'price' => 150,
            'is_active' => true,
            'can_subscribe' => true,
        ]);

        $pricing = app(PricingService::class)->getPriceForCountry($plan, 'SA');

        $this->assertSame(150, $pricing['price']);
    }
}
