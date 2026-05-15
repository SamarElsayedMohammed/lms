<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

final class SubscriptionService
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {}

    /**
     * Create a new subscription for a user (Supports Stacking)
     */
    public function createSubscription(
        User $user,
        SubscriptionPlan $plan,
        ?string $paymentMethod = null,
        ?float $walletAmount = null,
        ?float $gatewayAmount = null,
        array $discountMeta = []
    ): Subscription {
        return DB::transaction(function () use ($user, $plan, $paymentMethod, $walletAmount, $gatewayAmount, $discountMeta) {
            $existingSubscription = $this->getActiveSubscription($user);
            
            // 1. Same Plan Stacking (Extension)
            if ($existingSubscription && $existingSubscription->plan_id === $plan->id && !$existingSubscription->isLifetime()) {
                $baseDays = $plan->getDurationDays();
                if ($baseDays) {
                    $existingSubscription->extend($baseDays);
                    
                    // Create payment record for the extension
                    $this->createPaymentRecord($existingSubscription, $user, $plan, $paymentMethod, $walletAmount, $gatewayAmount, $discountMeta);
                    
                    Log::info('Subscription extended (Stacked)', [
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'subscription_id' => $existingSubscription->id,
                    ]);

                    return $existingSubscription;
                }
            }

            // 2. Different Plan Stacking (Queuing)
            $startsAt = now();
            $status = Subscription::STATUS_ACTIVE;
            $parentSubscriptionId = null;

            if ($existingSubscription) {
                // If user has an active subscription, the new one starts after it
                $startsAt = $existingSubscription->ends_at ?? now();
                $status = Subscription::STATUS_PENDING; // Mark as pending until it's time to start
                $parentSubscriptionId = $existingSubscription->id;
            }

            // Calculate dates
            $baseDays = $plan->getDurationDays();
            $endsAt = $plan->isLifetime() ? null : ($baseDays !== null ? $startsAt->copy()->addDays($baseDays) : null);

            // Create subscription
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $status,
                'auto_renew' => true,
                'parent_subscription_id' => $parentSubscriptionId,
                'notified_7_days' => false,
                'notified_3_days' => false,
                'notified_1_day' => false,
            ]);

            // Create payment record
            $this->createPaymentRecord($subscription, $user, $plan, $paymentMethod, $walletAmount, $gatewayAmount, $discountMeta);

            // Deduct from wallet if applicable
            if ($walletAmount > 0 && $user->wallet_balance >= $walletAmount) {
                $user->decrement('wallet_balance', $walletAmount);
            }

            Log::info('Subscription created' . ($status === Subscription::STATUS_PENDING ? ' (Queued)' : ''), [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'subscription_id' => $subscription->id,
            ]);

            // Process affiliate referral (wrapped in try-catch to prevent breaking subscription)
            try {
                $this->affiliateService->processReferral($user, $subscription);
            } catch (\Throwable $e) {
                Log::error('SubscriptionService: Affiliate referral processing failed', [
                    'message' => $e->getMessage(),
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                ]);
            }

            // Server-side tracking
            $this->trackSubscriptionPurchase($user, $subscription, $plan);

            return $subscription;
        });
    }

    /**
     * Helper to create payment record
     */
    private function createPaymentRecord($subscription, $user, $plan, $paymentMethod, $walletAmount, $gatewayAmount, array $discountMeta = []): void
    {
        $totalAmount = (float) $plan->price;
        $walletAmount = $walletAmount ?? 0;
        $gatewayAmount = $gatewayAmount ?? ($totalAmount - $walletAmount);

        // If discount was applied, use discounted total for the payment amount
        $paymentAmount = $totalAmount;
        if (!empty($discountMeta['discount_amount']) && $discountMeta['discount_amount'] > 0) {
            $paymentAmount = max($totalAmount - $discountMeta['discount_amount'], 0);
        }

        SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => $paymentAmount,
            'wallet_amount' => $walletAmount,
            'gateway_amount' => $gatewayAmount,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'payment_method' => $paymentMethod ?? 'wallet',
            'promo_code' => $discountMeta['promo_code'] ?? null,
            'original_amount' => !empty($discountMeta['promo_code']) ? $totalAmount : null,
            'discount_amount' => $discountMeta['discount_amount'] ?? 0,
            'paid_at' => now(),
        ]);
    }

    /**
     * Helper to track purchase
     */
    private function trackSubscriptionPurchase($user, $subscription, $plan): void
    {
        \App\Services\TrackingService::sendFacebookEvent('Purchase', [
            'em' => hash('sha256', $user->email),
        ], [
            'value' => (float) $plan->price,
            'currency' => 'EGP',
            'content_name' => $plan->name,
            'content_ids' => [(string) $plan->id],
            'content_type' => 'product',
        ]);
        \App\Services\TrackingService::sendGA4Event('purchase', [
            'transaction_id' => 'SUB-' . $subscription->id,
            'value' => (float) $plan->price,
            'currency' => 'EGP',
            'items' => [
                ['item_id' => (string) $plan->id, 'item_name' => $plan->name]
            ]
        ]);
    }

    /**
     * Renew an existing subscription
     */
    public function renewSubscription(Subscription $subscription): Subscription
    {
        $plan = $subscription->plan;

        if ($plan->isLifetime()) {
            return $subscription; // No need to renew lifetime
        }

        $days = $plan->getDurationDays();
        $subscription->extend($days);

        Log::info('Subscription renewed', [
            'subscription_id' => $subscription->id,
            'new_end_date' => $subscription->ends_at,
        ]);

        return $subscription;
    }

    /**
     * Renew subscription with payment (creates payment record and extends subscription)
     */
    public function renewWithPayment(
        User $user,
        Subscription $subscription,
        ?string $paymentMethod = null,
        ?float $walletAmount = null,
        ?float $gatewayAmount = null
    ): Subscription {
        $plan = $subscription->plan;

        if ($plan->isLifetime()) {
            throw new \InvalidArgumentException('لا يمكن تجديد اشتراك مدى الحياة.');
        }

        if ($subscription->user_id !== $user->id) {
            throw new \InvalidArgumentException('الاشتراك لا ينتمي لهذا المستخدم.');
        }

        return DB::transaction(function () use ($user, $subscription, $plan, $paymentMethod, $walletAmount, $gatewayAmount) {
            $totalAmount = (float) $plan->price;
            $walletAmount = $walletAmount ?? 0;
            $gatewayAmount = $gatewayAmount ?? ($totalAmount - $walletAmount);

            SubscriptionPayment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'amount' => $totalAmount,
                'wallet_amount' => $walletAmount,
                'gateway_amount' => $gatewayAmount,
                'status' => SubscriptionPayment::STATUS_COMPLETED,
                'payment_method' => $paymentMethod ?? 'wallet',
                'paid_at' => now(),
            ]);

            if ($walletAmount > 0 && $user->wallet_balance >= $walletAmount) {
                $user->decrement('wallet_balance', $walletAmount);
            }

            $this->renewSubscription($subscription);

            Log::info('Subscription renewed with payment', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
            ]);

            return $subscription->fresh(['plan']);
        });
    }

    /**
     * Cancel a subscription
     */
    public function cancelSubscription(Subscription $subscription, ?string $reason = null): bool
    {
        $result = $subscription->cancel($reason);

        Log::info('Subscription cancelled', [
            'subscription_id' => $subscription->id,
            'reason' => $reason,
        ]);

        return $result;
    }

    /**
     * Calculate wallet vs gateway payment split for subscription.
     * Used when user pays with wallet + Kashier.
     *
     * @return array{wallet_amount: float, gateway_amount: float}
     */
    public function walletAndGatewayPayment(User $user, SubscriptionPlan $plan, float $totalAmount, bool $useWallet): array
    {
        $walletAmount = 0.0;
        $gatewayAmount = $totalAmount;

        if ($useWallet && $user->wallet_balance > 0) {
            $walletAmount = (float) min($user->wallet_balance, $totalAmount);
            $gatewayAmount = $totalAmount - $walletAmount;
        }

        return [
            'wallet_amount' => round($walletAmount, 2),
            'gateway_amount' => round($gatewayAmount, 2),
        ];
    }

    /**
     * Get active subscription for a user
     */
    public function getActiveSubscription(User $user): ?Subscription
    {
        return Subscription::forUser($user->id)
            ->active()
            ->with('plan')
            ->first();
    }

    /**
     * Check if user has access (no grace period - access ends immediately on expiry)
     */
    public function checkAccess(User $user): bool
    {
        return $this->getActiveSubscription($user) !== null;
    }

    /**
     * Get subscription status for a user (no grace period)
     */
    public function getSubscriptionStatus(User $user): array
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return [
                'has_access' => false,
                'status' => 'no_subscription',
                'subscription' => null,
                'message' => 'لا يوجد اشتراك نشط',
            ];
        }

        return [
            'has_access' => true,
            'status' => 'active',
            'subscription' => $subscription,
            'days_remaining' => $subscription->days_remaining,
            'is_lifetime' => $subscription->isLifetime(),
            'auto_renew' => $subscription->auto_renew,
        ];
    }

    /**
     * Handle expired subscriptions (called by scheduler).
     * Attempts wallet-based auto-renewal for subscriptions with auto_renew enabled
     * before marking them as expired.
     */
    public function handleExpiredSubscriptions(): int
    {
        $count = 0;

        $expiredSubscriptions = Subscription::with(['user', 'plan'])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($expiredSubscriptions as $subscription) {
            if ($subscription->auto_renew && $subscription->plan && !$subscription->plan->isLifetime()) {
                $user = $subscription->user;
                $plan = $subscription->plan;
                $price = (float) $plan->price;

                if ($user && $user->wallet_balance >= $price) {
                    try {
                        $this->renewWithPayment($user, $subscription, 'wallet', $price, 0);

                        Log::info('Subscription auto-renewed via wallet', [
                            'subscription_id' => $subscription->id,
                            'user_id' => $user->id,
                            'amount' => $price,
                        ]);

                        continue;
                    } catch (\Throwable $e) {
                        Log::warning('Auto-renewal failed, marking as expired', [
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $subscription->status = Subscription::STATUS_EXPIRED;
            $subscription->save();
            $count++;

            Log::info('Subscription marked as expired', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);
        }

        return $count;
    }

    /**
     * Get subscriptions needing 7-day expiry notification
     */
    public function getSubscriptionsForNotification7Days(): \Illuminate\Database\Eloquent\Collection
    {
        return Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays(7))
            ->where('notified_7_days', false)
            ->with(['user', 'plan'])
            ->get();
    }

    /**
     * Get subscriptions needing 3-day expiry notification
     */
    public function getSubscriptionsForNotification3Days(): \Illuminate\Database\Eloquent\Collection
    {
        return Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays(3))
            ->where('notified_3_days', false)
            ->with(['user', 'plan'])
            ->get();
    }

    /**
     * Get subscriptions needing 24-hour (1-day) expiry notification
     */
    public function getSubscriptionsForNotification1Day(): \Illuminate\Database\Eloquent\Collection
    {
        return Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDay())
            ->where('notified_1_day', false)
            ->with(['user', 'plan'])
            ->get();
    }

    /**
     * Mark a subscription as notified for a specific threshold
     */
    public function markNotified(Subscription $subscription, int $thresholdDays): void
    {
        $field = match ($thresholdDays) {
            7 => 'notified_7_days',
            3 => 'notified_3_days',
            1 => 'notified_1_day',
            default => null,
        };

        if ($field) {
            $subscription->{$field} = true;
            $subscription->save();
        }
    }

    /**
     * Update user subscription settings (auto-renew toggle)
     */
    public function updateUserSettings(User $user, array $settings): ?Subscription
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return null;
        }

        if (isset($settings['auto_renew'])) {
            $subscription->auto_renew = (bool) $settings['auto_renew'];
        }

        $subscription->save();

        return $subscription;
    }

    /**
     * Get payment history for a user
     */
    public function getPaymentHistory(User $user, int $perPage = 10): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return SubscriptionPayment::forUser($user->id)
            ->with('subscription.plan')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
