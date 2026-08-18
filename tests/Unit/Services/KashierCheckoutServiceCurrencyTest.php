<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Payment\KashierCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KashierCheckoutServiceCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::query()->updateOrCreate(['name' => 'kashier_merchant_id'], ['value' => 'TEST_MERCHANT', 'type' => 'string']);
        Setting::query()->updateOrCreate(['name' => 'kashier_api_key'], ['value' => 'TEST_API_KEY', 'type' => 'string']);
        Setting::query()->updateOrCreate(['name' => 'kashier_mode'], ['value' => 'test', 'type' => 'string']);
        Setting::query()->updateOrCreate(['name' => 'kashier_status'], ['value' => '1', 'type' => 'boolean']);
    }

    /**
     * @dataProvider checkoutCurrencyProvider
     */
    public function test_create_checkout_session_uses_resolved_currency(string $currency): void
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

        $user = User::factory()->create();

        $checkout = app(KashierCheckoutService::class)->createCheckoutSession(
            $plan,
            $user,
            600.0,
            $currency,
        );

        $this->assertSame($currency, $checkout['currency']);
        $this->assertStringContainsString('currency=' . $currency, $checkout['url']);
        $this->assertStringContainsString('amount=600.00', $checkout['url']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function checkoutCurrencyProvider(): array
    {
        return [
            'SAR' => ['SAR'],
            'USD' => ['USD'],
            'EUR' => ['EUR'],
            'EGP' => ['EGP'],
        ];
    }
}
