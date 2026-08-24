<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoreBillingDoubleBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'double_billing_test@skillso.test',
            'wallet_balance' => 5000, // User has plenty of wallet balance
        ]);

        $this->plan = SubscriptionPlan::create([
            'name' => 'Monthly Plan',
            'slug' => 'monthly',
            'price' => 500,
            'usd_price' => 20,
            'billing_cycle' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
        ]);
    }

    /**
     * Prove that an Apple-managed subscription with auto_renew enabled
     * does NOT get auto-renewed by deducting wallet balance when expires.
     */
    public function test_apple_store_subscription_is_never_auto_renewed_via_wallet(): void
    {
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subMinute(), // Past expiry
            'auto_renew' => true,
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_apple_no_wallet',
        ]);

        $initialWallet = (float) $this->user->wallet_balance;

        $service = app(SubscriptionService::class);
        $count = $service->handleExpiredSubscriptions();

        // 1. Subscription must be marked expired (awaiting Apple Server Notification)
        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_EXPIRED, $sub->status);

        // 2. User's wallet balance MUST remain untouched!
        $this->user->refresh();
        $this->assertEquals($initialWallet, (float) $this->user->wallet_balance);

        // 3. No wallet payment record created
        $walletPayments = SubscriptionPayment::where('user_id', $this->user->id)
            ->where('payment_method', 'wallet')
            ->count();
        $this->assertEquals(0, $walletPayments);
    }

    /**
     * Prove that a Google Play-managed subscription with auto_renew enabled
     * does NOT get auto-renewed by deducting wallet balance when expires.
     */
    public function test_google_play_subscription_is_never_auto_renewed_via_wallet(): void
    {
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subMinute(), // Past expiry
            'auto_renew' => true,
            'store_provider' => 'google_play',
            'store_original_transaction_id' => 'token_google_no_wallet',
        ]);

        $initialWallet = (float) $this->user->wallet_balance;

        $service = app(SubscriptionService::class);
        $count = $service->handleExpiredSubscriptions();

        // 1. Subscription marked expired
        $sub->refresh();
        $this->assertEquals(Subscription::STATUS_EXPIRED, $sub->status);

        // 2. User's wallet balance remains completely untouched
        $this->user->refresh();
        $this->assertEquals($initialWallet, (float) $this->user->wallet_balance);

        // 3. No wallet payment record created
        $walletPayments = SubscriptionPayment::where('user_id', $this->user->id)
            ->where('payment_method', 'wallet')
            ->count();
        $this->assertEquals(0, $walletPayments);
    }

    /** The read-path lazy synchronizer must obey the same no-double-billing rule. */
    public function test_store_subscription_is_never_wallet_renewed_during_entitlement_read(): void
    {
        $sub = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subMinute(),
            'auto_renew' => true,
            'store_provider' => 'app_store',
            'store_original_transaction_id' => 'orig_tx_lazy_read',
        ]);

        $initialWallet = (float) $this->user->wallet_balance;

        $active = app(SubscriptionService::class)->getActiveSubscription($this->user);

        $this->assertNull($active);
        $this->assertEquals(Subscription::STATUS_EXPIRED, $sub->fresh()->status);
        $this->assertEquals($initialWallet, (float) $this->user->fresh()->wallet_balance);
        $this->assertDatabaseMissing('subscription_payments', [
            'user_id' => $this->user->id,
            'payment_method' => 'wallet',
        ]);
    }
}
