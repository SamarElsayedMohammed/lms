<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use App\Services\Payment\KashierCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubscriptionGeoPricingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->create(['name' => 'kashier_merchant_id', 'value' => 'TEST_MERCHANT']);
        Setting::query()->create(['name' => 'kashier_api_key', 'value' => 'TEST_API_KEY']);
        Setting::query()->create(['name' => 'kashier_mode', 'value' => 'test']);

        $this->plan = SubscriptionPlan::create([
            'name' => 'Premium Plan',
            'slug' => 'premium-plan',
            'price' => 1000,
            'usd_price' => 50,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $this->seedCountryPrice('EG', 'EGP', 1000);
        $this->seedCountryPrice('SA', 'SAR', 600);
        $this->seedCountryPrice('US', 'USD', 50);
        $this->seedCountryPrice('FR', 'EUR', 10);
    }

    public function test_get_plans_uses_usd_default_for_country_without_override(): void
    {
        $response = $this->withHeaders([
            'X-Vercel-IP-Country' => 'JO',
        ])->getJson('/api/v1/subscription/plans?test_country=JO');

        $response->assertOk();

        $plan = collect($response->json('data.plans'))->firstWhere('id', $this->plan->id);

        $this->assertNotNull($plan);
        $this->assertSame(50, $plan['display_price']);
        $this->assertSame('USD', $plan['display_currency']);
        $this->assertSame('USD', $plan['resolved_currency']);
        $this->assertSame('default', $plan['price_source']);
    }

    /**
     * @dataProvider checkoutCurrencyMatrixProvider
     */
    public function test_subscribe_checkout_url_matches_resolved_currency(
        string $country,
        string $expectedCurrency,
        int $expectedAmount,
    ): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withHeaders([
            'X-Vercel-IP-Country' => $country,
        ])->postJson('/api/v1/subscription/subscribe?test_country=' . $country, [
            'plan_id' => $this->plan->id,
            'payment_method' => 'kashier',
            'use_wallet' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.requires_checkout', true);

        $checkoutUrl = (string) $response->json('data.checkout_url');

        $this->assertStringContainsString('currency=' . $expectedCurrency, $checkoutUrl);
        $this->assertStringContainsString('amount=' . number_format((float) $expectedAmount, 2, '.', ''), $checkoutUrl);
        $this->assertSame($expectedAmount, $response->json('data.payment.total_amount'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function checkoutCurrencyMatrixProvider(): array
    {
        return [
            'EG base' => ['EG', 'EGP', 1000],
            'SA override' => ['SA', 'SAR', 600],
            'US override' => ['US', 'USD', 50],
            'FR override' => ['FR', 'EUR', 10],
            'JO usd default' => ['JO', 'USD', 50],
        ];
    }

    public function test_subscribe_passes_resolved_currency_to_kashier_service(): void
    {
        $user = User::factory()->create();

        $mock = $this->mock(KashierCheckoutService::class);
        $mock->shouldReceive('createCheckoutSession')
            ->once()
            ->with(
                \Mockery::type(SubscriptionPlan::class),
                \Mockery::type(User::class),
                600.0,
                'SAR',
            )
            ->andReturn([
                'url' => 'https://checkout.kashier.io?currency=SAR&amount=600.00',
                'order_id' => 'sub_test',
                'hash' => 'hash',
                'amount' => 600.0,
                'currency' => 'SAR',
                'merchant_id' => 'TEST_MERCHANT',
                'mode' => 'test',
                'meta' => [],
            ]);

        $response = $this->actingAs($user)->withHeaders([
            'X-Vercel-IP-Country' => 'SA',
        ])->postJson('/api/v1/subscription/subscribe?test_country=SA', [
            'plan_id' => $this->plan->id,
            'payment_method' => 'kashier',
            'use_wallet' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://checkout.kashier.io?currency=SAR&amount=600.00');
    }

    private function seedCountryPrice(string $countryCode, string $currencyCode, float $price): void
    {
        SubscriptionPlanPrice::create([
            'plan_id' => $this->plan->id,
            'country_code' => $countryCode,
            'currency_code' => $currencyCode,
            'price' => $price,
            'is_active' => true,
            'can_subscribe' => true,
        ]);
    }
}
