<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PricingServiceUsdFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_egypt_without_override_uses_egp_base_price(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'price' => 1000,
            'usd_price' => 50,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $pricing = app(PricingService::class)->getPriceForCountry($plan, 'EG');

        $this->assertSame(1000, $pricing['price']);
        $this->assertSame('EGP', $pricing['currency_code']);
        $this->assertSame('default', $pricing['price_source']);
        $this->assertTrue($pricing['can_subscribe']);
    }

    public function test_country_without_override_uses_usd_default_price(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'price' => 1000,
            'usd_price' => 50,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $pricing = app(PricingService::class)->getPriceForCountry($plan, 'JO');

        $this->assertSame(50, $pricing['price']);
        $this->assertSame('USD', $pricing['currency_code']);
        $this->assertSame('default', $pricing['price_source']);
        $this->assertTrue($pricing['can_subscribe']);
    }

    public function test_country_without_override_and_missing_usd_price_is_not_subscribable(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'price' => 1000,
            'usd_price' => null,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $pricing = app(PricingService::class)->getPriceForCountry($plan, 'JO');

        $this->assertSame(0, $pricing['price']);
        $this->assertSame('USD', $pricing['currency_code']);
        $this->assertFalse($pricing['can_subscribe']);
    }

    public function test_country_override_still_takes_priority_over_usd_default(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'price' => 1000,
            'usd_price' => 50,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        SubscriptionPlanPrice::create([
            'plan_id' => $plan->id,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'price' => 600,
            'is_active' => true,
            'can_subscribe' => true,
        ]);

        $pricing = app(PricingService::class)->getPriceForCountry($plan, 'SA');

        $this->assertSame(600, $pricing['price']);
        $this->assertSame('SAR', $pricing['currency_code']);
        $this->assertSame('country_override', $pricing['price_source']);
    }
}
