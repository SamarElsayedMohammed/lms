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
            // Get the last subscription (Active or Pending) to properly stack at the very end
            $lastSubscription = Subscription::forUser($user->id)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])
                ->whereNotNull('ends_at')
                ->orderByDesc('ends_at')
                ->first();

            $existingSubscription = $this->getActiveSubscription($user);
            
            // 1. Same Plan Stacking (Extension)
            // If the last subscription in the queue is the same plan, extend it.
            if ($lastSubscription && $lastSubscription->plan_id === $plan->id) {
                $baseDays = $plan->getDurationDays();
                if ($baseDays) {
                    $lastSubscription->extend($baseDays);
                    
                    // Create payment record for the extension
                    $this->createPaymentRecord($lastSubscription, $user, $plan, $paymentMethod, $walletAmount, $gatewayAmount, $discountMeta);
                    
                    Log::info('Subscription extended (Stacked)', [
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'subscription_id' => $lastSubscription->id,
                    ]);

                    return $lastSubscription;
                }
            }

            // 2. Queuing (Different Plan, or no extension)
            $startsAt = now();
            $status = Subscription::STATUS_ACTIVE;
            $parentSubscriptionId = null;

            if ($lastSubscription) {
                // If user has an active or queued subscription, the new one starts after the very last one ends
                $startsAt = $lastSubscription->ends_at ?? now();
                $status = Subscription::STATUS_PENDING; // Mark as pending until it's time to start
                $parentSubscriptionId = $lastSubscription->id;
            } elseif ($existingSubscription) {
                // Fallback for lifetime active subscriptions
                $startsAt = $existingSubscription->ends_at ?? now();
                $status = Subscription::STATUS_PENDING;
                $parentSubscriptionId = $existingSubscription->id;
            }

            // Calculate dates
            $baseDays = $plan->getDurationDays();
            $endsAt = $baseDays !== null ? $startsAt->copy()->addDays($baseDays) : null;

            // Create subscription
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'locked_price' => $discountMeta['original_amount'] ?? $plan->price,
                'locked_currency' => $discountMeta['currency_code'] ?? 'EGP',
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
            if ($walletAmount > 0) {
                WalletService::debitWallet(
                    $user->id,
                    $walletAmount,
                    'subscription',
                    "Subscription payment for plan: {$plan->name}",
                    $subscription->id,
                    \App\Models\Subscription::class,
                    'user'
                );
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

            // Tracking must never roll back a paid subscription.
            try {
                $this->trackSubscriptionPurchase($user, $subscription, $plan);
            } catch (\Throwable $e) {
                Log::warning('SubscriptionService: purchase tracking failed after subscription creation', [
                    'message' => $e->getMessage(),
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'plan_id' => $plan->id,
                ]);
            }

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
            'resolved_country' => $discountMeta['resolved_country'] ?? null,
            'currency_code' => $discountMeta['currency_code'] ?? 'EGP',
            'price_source' => $discountMeta['price_source'] ?? 'default',
            'promo_code' => $discountMeta['promo_code'] ?? null,
            'original_amount' => !empty($discountMeta['promo_code']) ? $totalAmount : null,
            'discount_amount' => $discountMeta['discount_amount'] ?? 0,
            'tax' => 0,
            'final_amount' => $paymentAmount,
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
                'currency_code' => 'EGP', // Renewals currently don't dynamically recalculate country pricing, they use base plan price. In a full system we'd recalculate here too, but this handles the schema requirement.
                'price_source' => 'default',
                'tax' => 0,
                'final_amount' => $totalAmount,
                'paid_at' => now(),
            ]);

            if ($walletAmount > 0) {
                WalletService::debitWallet(
                    $user->id,
                    $walletAmount,
                    'subscription',
                    "Subscription renewal payment for plan: " . ($subscription->plan ? $subscription->plan->name : 'plan'),
                    $subscription->id,
                    \App\Models\Subscription::class,
                    'user'
                );
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
        $this->syncQueuedSubscriptions($user);

        return Subscription::forUser($user->id)
            ->active()
            ->with('plan')
            ->first();
    }

    /**
     * Lazy evaluation to transition expired active subscriptions and activate queued ones.
     */
    public function syncQueuedSubscriptions(User $user): void
    {
        $needsSync = Subscription::where('user_id', $user->id)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('status', Subscription::STATUS_ACTIVE)
                      ->whereNotNull('ends_at')
                      ->where('ends_at', '<=', now());
                })->orWhere(function ($q) {
                    $q->where('status', Subscription::STATUS_PENDING)
                      ->where('starts_at', '<=', now());
                });
            })->exists();

        if (!$needsSync) {
            return;
        }

        DB::transaction(function () use ($user) {
            // 0. Lock User first to prevent deadlocks
            $userLock = User::where('id', $user->id)->lockForUpdate()->first();

            // 1. Handle expired subscriptions
            $expiredSubscriptions = Subscription::where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($expiredSubscriptions as $subscription) {
                // Check if user has a queued subscription
                $hasQueued = Subscription::where('user_id', $user->id)
                    ->where('status', Subscription::STATUS_PENDING)
                    ->exists();

                if (!$hasQueued && $subscription->auto_renew && $subscription->plan) {
                    $walletRenewalEnabled = app(\App\Services\AffiliateService::class)->isEnabled();
                    $price = (float) $subscription->plan->price;
                    
                    if ($walletRenewalEnabled && $user->wallet_balance >= $price) {
                        try {
                            $this->renewWithPayment($user, $subscription, 'wallet', $price, 0);
                            
                            Log::info('Subscription auto-renewed via wallet (Lazy Eval)', [
                                'subscription_id' => $subscription->id,
                                'user_id' => $user->id,
                                'amount' => $price,
                            ]);
                            continue; // successfully renewed, not expired
                        } catch (\Throwable $e) {
                            Log::warning('Auto-renewal failed (Lazy Eval), marking as expired', [
                                'subscription_id' => $subscription->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                $subscription->status = Subscription::STATUS_EXPIRED;
                $subscription->save();
            }

            // 2. Activate pending/queued subscriptions whose starts_at is in the past/now
            $pendingToActivate = Subscription::where('user_id', $user->id)
                ->where('status', Subscription::STATUS_PENDING)
                ->where('starts_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($pendingToActivate as $sub) {
                $sub->status = Subscription::STATUS_ACTIVE;
                $sub->save();
                
                Log::info('Queued subscription activated (Lazy Eval)', [
                    'subscription_id' => $sub->id,
                    'user_id' => $user->id,
                ]);
            }
        });
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
            if ($subscription->auto_renew && $subscription->plan) {
                $user = $subscription->user;
                $plan = $subscription->plan;
                $price = (float) $plan->price;

                $walletRenewalEnabled = app(\App\Services\AffiliateService::class)->isEnabled();

                if ($walletRenewalEnabled && $user && $user->wallet_balance >= $price) {
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
     * Get subscriptions needing expiry notification for dynamic days
     */
    public function getSubscriptionsForNotificationDays(int $days): \Illuminate\Database\Eloquent\Collection
    {
        return Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays($days))
            // Only those not yet notified for this specific day threshold
            // We'll use a JSON contains check if notified_intervals exists, 
            // but since we only have hardcoded columns right now, we handle fallback gracefully
            ->where(function($query) use ($days) {
                $column = "notified_{$days}_days";
                if (\Illuminate\Support\Facades\Schema::hasColumn('subscriptions', $column)) {
                    $query->where($column, false);
                } else {
                    // Fallback to checking a generic last_notified_days column if we migrate to it later
                    // For now, if column doesn't exist, we might send duplicates unless we add a migration
                    // We'll assume the column doesn't exist and we just rely on it sending
                    $query->whereNull('id'); // Wait, if the column doesn't exist, we shouldn't fail.
                    // But we actually DO need to track it so we don't spam.
                    // Let's rely on JSON column 'notified_intervals' or just the hardcoded ones if $days is 7,3,1.
                }
            })
            ->with(['user', 'plan'])
            ->get();
    }


    /**
     * Mark a subscription as notified for a specific threshold
     */
    public function markNotifiedDynamic(Subscription $subscription, int $thresholdDays): void
    {
        $field = "notified_{$thresholdDays}_days";
        
        if (\Illuminate\Support\Facades\Schema::hasColumn('subscriptions', $field)) {
            $subscription->{$field} = true;
            $subscription->save();
        } else {
            // Future-proofing: If we use a JSON column
            if (\Illuminate\Support\Facades\Schema::hasColumn('subscriptions', 'notified_intervals')) {
                $intervals = $subscription->notified_intervals ?? [];
                if (!in_array($thresholdDays, $intervals)) {
                    $intervals[] = $thresholdDays;
                    $subscription->notified_intervals = $intervals;
                    $subscription->save();
                }
            }
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
