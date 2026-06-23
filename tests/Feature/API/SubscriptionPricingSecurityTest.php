<?php

namespace Tests\Feature\API;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class SubscriptionPricingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->plan = SubscriptionPlan::factory()->create([
            'price' => 1000,
            'is_active' => true,
        ]);
        
        SubscriptionPlanPrice::create([
            'plan_id' => $this->plan->id,
            'country_code' => 'EG',
            'currency_code' => 'EGP',
            'price' => 800,
            'is_active' => true,
            'can_subscribe' => true,
        ]);
        
        SubscriptionPlanPrice::create([
            'plan_id' => $this->plan->id,
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'price' => 150,
            'is_active' => true,
            'can_subscribe' => true,
        ]);
        
        SubscriptionPlanPrice::create([
            'plan_id' => $this->plan->id,
            'country_code' => 'AE',
            'currency_code' => 'AED',
            'price' => 200,
            'is_active' => false,
            'can_subscribe' => false,
        ]);
    }

    public function test_forged_cf_ipcountry_does_not_affect_pricing()
    {
        // Without CF-Connecting-IP, the CF-IPCountry should be ignored
        $response = $this->withHeaders([
            'CF-IPCountry' => 'SA',
        ])->getJson('/api/v1/subscription/plans');

        $response->assertStatus(200);
        $this->assertEquals('EG', $response->json('data.detected_country')); // default fallback
    }

    public function test_valid_signed_proxy_header_resolves_country()
    {
        $timestamp = time();
        $secret = config('app.proxy_secret', config('app.key'));
        $signature = hash_hmac('sha256', 'SA' . $timestamp, $secret);

        $response = $this->withHeaders([
            'X-Skillso-Resolved-Country' => 'SA',
            'X-Skillso-Country-Timestamp' => (string) $timestamp,
            'X-Skillso-Country-Signature' => $signature,
        ])->getJson('/api/v1/subscription/plans');

        $response->assertStatus(200);
        $this->assertEquals('SA', $response->json('data.detected_country'));
        $this->assertEquals(150, $response->json('data.plans.0.price'));
        $this->assertEquals('SAR', $response->json('data.plans.0.display_currency'));
    }

    public function test_expired_signature_is_ignored()
    {
        $timestamp = time() - 400; // Older than 5 minutes
        $secret = config('app.proxy_secret', config('app.key'));
        $signature = hash_hmac('sha256', 'SA' . $timestamp, $secret);

        $response = $this->withHeaders([
            'X-Skillso-Resolved-Country' => 'SA',
            'X-Skillso-Country-Timestamp' => (string) $timestamp,
            'X-Skillso-Country-Signature' => $signature,
        ])->getJson('/api/v1/subscription/plans');

        $response->assertStatus(200);
        $this->assertEquals('EG', $response->json('data.detected_country'));
    }

    public function test_inactive_override_marks_can_subscribe_false()
    {
        $timestamp = time();
        $secret = config('app.proxy_secret', config('app.key'));
        $signature = hash_hmac('sha256', 'AE' . $timestamp, $secret);

        $response = $this->withHeaders([
            'X-Skillso-Resolved-Country' => 'AE',
            'X-Skillso-Country-Timestamp' => (string) $timestamp,
            'X-Skillso-Country-Signature' => $signature,
        ])->getJson('/api/v1/subscription/plans');

        $response->assertStatus(200);
        $this->assertEquals('AE', $response->json('data.detected_country'));
        $this->assertFalse($response->json('data.plans.0.can_subscribe'));
        $this->assertFalse($response->json('data.plans.0.is_available'));
    }

    public function test_checkout_recalculates_price_server_side()
    {
        $user = User::factory()->create();
        
        $timestamp = time();
        $secret = config('app.proxy_secret', config('app.key'));
        $signature = hash_hmac('sha256', 'SA' . $timestamp, $secret);

        $response = $this->actingAs($user)->withHeaders([
            'X-Skillso-Resolved-Country' => 'SA',
            'X-Skillso-Country-Timestamp' => (string) $timestamp,
            'X-Skillso-Country-Signature' => $signature,
        ])->postJson('/api/v1/subscription/subscribe', [
            'plan_id' => $this->plan->id,
            'payment_method' => 'wallet',
            'use_wallet' => false,
        ]);

        $response->assertStatus(200);
        
        // Ensure Kashier pending gets the SA price (150)
        $this->assertEquals(150, $response->json('data.payment.total_amount'));
    }

    public function test_invalid_country_codes_are_skipped(): void
    {
        // XX is Cloudflare's "unknown" code - should be skipped
        $response = $this->withHeaders([
            'X-Vercel-IP-Country' => 'XX',
        ])->getJson('/api/v1/subscription/plans');

        $response->assertStatus(200);
        // Should fallback to EG (default), not use XX
        $this->assertEquals('EG', $response->json('data.detected_country'));
    }

    public function test_vercel_ip_country_header_resolves_country(): void
    {
        // Create a country price for KW
        \App\Models\SubscriptionPlanPrice::create([
            'plan_id' => $this->plan->id,
            'country_code' => 'KW',
            'currency_code' => 'KWD',
            'price' => 10,
            'is_active' => true,
            'can_subscribe' => true,
        ]);

        $response = $this->withHeaders([
            'X-Vercel-IP-Country' => 'KW',
        ])->getJson('/api/v1/subscription/plans');

        $response->assertStatus(200);
        $this->assertEquals('KW', $response->json('data.detected_country'));
    }

    public function test_x_country_header_resolves_country(): void
    {
        $response = $this->withHeaders([
            'X-Country' => 'SA',
        ])->getJson('/api/v1/subscription/plans');

        $response->assertStatus(200);
        $this->assertEquals('SA', $response->json('data.detected_country'));
        $this->assertEquals(150, $response->json('data.plans.0.display_price'));
    }
}
