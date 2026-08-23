<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

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
        $subscription = DB::transaction(function () use ($user, $plan, $paymentMethod, $walletAmount, $gatewayAmount, $discountMeta) {
            User::where('id', $user->id)->lockForUpdate()->first();

            // Get the last subscription (Active or Pending) to properly stack at the very end
            $lastSubscription = Subscription::forUser($user->id)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])
                ->whereNotNull('ends_at')
                ->orderByDesc('ends_at')
                ->lockForUpdate()
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

            // 2. Queuing or Replacement (Different Plan, or no extension)
            $startsAt = now();
            $status = Subscription::STATUS_ACTIVE;
            $parentSubscriptionId = null;

            if ($lastSubscription && $lastSubscription->ends_at === null) {
                // If user currently has an active lifetime plan, upgrade/replace it immediately
                $startsAt = now();
                $status = Subscription::STATUS_ACTIVE;
                $parentSubscriptionId = $lastSubscription->id;
                $lastSubscription->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Replaced by new subscription',
                ]);
            } elseif ($lastSubscription) {
                // If user has an active or queued subscription, the new one starts after the very last one ends
                $startsAt = $lastSubscription->ends_at ?? now();
                $status = Subscription::STATUS_PENDING; // Mark as pending until it's time to start
                $parentSubscriptionId = $lastSubscription->id;
            } elseif ($existingSubscription) {
                // Fallback for lifetime active subscriptions
                $startsAt = now();
                $status = Subscription::STATUS_ACTIVE;
                $parentSubscriptionId = $existingSubscription->id;
                $existingSubscription->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Replaced by new subscription',
                ]);
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

            // Deduct from wallet if applicable (ledger is always EGP)
            if ($walletAmount > 0) {
                $debitEgp = isset($discountMeta['wallet_amount_egp'])
                    ? (float) $discountMeta['wallet_amount_egp']
                    : $walletAmount;
                if (! empty($discountMeta['currency_code']) && ! isset($discountMeta['wallet_amount_egp'])) {
                    $debitEgp = app(CurrencyConversionService::class)->convertToEgp(
                        $walletAmount,
                        (string) $discountMeta['currency_code'],
                    );
                }
                WalletService::debitWallet(
                    $user->id,
                    $debitEgp,
                    'subscription',
                    "Subscription payment for plan: {$plan->name}",
                    $subscription->id,
                    \App\Models\Subscription::class,
                    'user'
                );
            }

            Log::info('Subscription created'.($status === Subscription::STATUS_PENDING ? ' (Queued)' : ''), [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'subscription_id' => $subscription->id,
            ]);

            try {
                $this->affiliateService->processReferral($user, $subscription);
            } catch (\Throwable $e) {
                Log::error('SubscriptionService: Affiliate referral processing failed', [
                    'message' => $e->getMessage(),
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                ]);
            }

            return $subscription;
        });

        // Outbound HTTP must not hold the DB transaction open (SB-DB-01).
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
    }

    /**
     * Helper to create payment record
     */
    private function createPaymentRecord($subscription, $user, $plan, $paymentMethod, $walletAmount, $gatewayAmount, array $discountMeta = []): void
    {
        // The controller resolves country pricing and promo discounts before it calls
        // this service. Persist that authoritative final amount instead of reverting
        // the payment record to the plan's default-currency price.
        $totalAmount = isset($discountMeta['total_amount'])
            ? (float) $discountMeta['total_amount']
            : (float) $plan->price;
        $walletAmount = $walletAmount ?? 0;
        $gatewayAmount = $gatewayAmount ?? ($totalAmount - $walletAmount);
        $currencyCode = strtoupper((string) ($discountMeta['currency_code'] ?? 'EGP'));
        $conversion = app(CurrencyConversionService::class);
        $amountEgp = isset($discountMeta['amount_egp'])
            ? (float) $discountMeta['amount_egp']
            : $conversion->convertToEgp($totalAmount, $currencyCode);
        $walletAmountEgp = isset($discountMeta['wallet_amount_egp'])
            ? (float) $discountMeta['wallet_amount_egp']
            : $conversion->convertToEgp($walletAmount, $currencyCode);
        $gatewayAmountEgp = isset($discountMeta['gateway_amount_egp'])
            ? (float) $discountMeta['gateway_amount_egp']
            : max(0.0, $amountEgp - $walletAmountEgp);
        $exchangeRate = $totalAmount > 0 ? $amountEgp / $totalAmount : 1.0;

        $paymentAmount = $totalAmount;

        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'amount' => $paymentAmount,
            'wallet_amount' => $walletAmount,
            'gateway_amount' => $gatewayAmount,
            'amount_egp' => $amountEgp,
            'wallet_amount_egp' => $walletAmountEgp,
            'gateway_amount_egp' => $gatewayAmountEgp,
            'exchange_rate_snapshot' => $exchangeRate,
            'status' => SubscriptionPayment::STATUS_COMPLETED,
            'payment_method' => $paymentMethod ?? 'wallet',
            'resolved_country' => $discountMeta['resolved_country'] ?? null,
            'currency_code' => $discountMeta['currency_code'] ?? 'EGP',
            'price_source' => $discountMeta['price_source'] ?? 'default',
            'promo_code' => $discountMeta['promo_code'] ?? null,
            'original_amount' => ! empty($discountMeta['promo_code'])
                ? (float) ($discountMeta['original_amount'] ?? $totalAmount)
                : null,
            'discount_amount' => $discountMeta['discount_amount'] ?? 0,
            'tax' => 0,
            'final_amount' => $paymentAmount,
            'paid_at' => now(),
        ]);

        if (! empty($discountMeta['promo_code'])) {
            try {
                app(\App\Services\SubscriptionPromoService::class)->consumePromo($payment->id, (string) $discountMeta['promo_code']);
            } catch (\Throwable $e) {
                Log::error('SubscriptionService: Promo consumption failed', [
                    'payment_id' => $payment->id,
                    'promo_code' => $discountMeta['promo_code'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Helper to track purchase
     */
    private function trackSubscriptionPurchase($user, $subscription, $plan): void
    {
        \App\Jobs\SendTrackingEventJob::dispatch('facebook', 'Purchase', [
            'user_data' => [
                'em' => hash('sha256', $user->email),
                'client_ip_address' => request()?->ip(),
                'client_user_agent' => request()?->userAgent(),
            ],
            'custom_data' => [
                'value' => (float) $plan->price,
                'currency' => 'EGP',
                'content_name' => $plan->name,
                'content_ids' => [(string) $plan->id],
                'content_type' => 'product',
            ],
        ]);
        \App\Jobs\SendTrackingEventJob::dispatch('ga4', 'purchase', [
            'params' => [
                'transaction_id' => 'SUB-'.$subscription->id,
                'value' => (float) $plan->price,
                'currency' => 'EGP',
                'items' => [
                    ['item_id' => (string) $plan->id, 'item_name' => $plan->name],
                ],
            ],
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
        ?float $gatewayAmount = null,
        ?float $localAmount = null,
        string $currencyCode = 'EGP',
        ?float $walletAmountEgp = null,
    ): Subscription {
        $plan = $subscription->plan;

        if ($subscription->user_id !== $user->id) {
            throw new \InvalidArgumentException('الاشتراك لا ينتمي لهذا المستخدم.');
        }

        return DB::transaction(function () use ($user, $subscription, $plan, $paymentMethod, $walletAmount, $gatewayAmount, $localAmount, $currencyCode, $walletAmountEgp) {
            $totalAmount = $localAmount !== null ? (float) $localAmount : (float) $plan->price;
            $walletAmount = $walletAmount ?? 0;
            $gatewayAmount = $gatewayAmount ?? ($totalAmount - $walletAmount);
            $currencyCode = strtoupper($currencyCode ?: 'EGP');
            $conversion = app(CurrencyConversionService::class);
            $amountEgp = $conversion->convertToEgp($totalAmount, $currencyCode);
            $resolvedWalletAmountEgp = $walletAmountEgp
                ?? $conversion->convertToEgp($walletAmount, $currencyCode);
            $gatewayAmountEgp = max(0.0, $amountEgp - $resolvedWalletAmountEgp);
            $exchangeRate = $totalAmount > 0 ? $amountEgp / $totalAmount : 1.0;

            SubscriptionPayment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'amount' => $totalAmount,
                'wallet_amount' => $walletAmount,
                'gateway_amount' => $gatewayAmount,
                'amount_egp' => $amountEgp,
                'wallet_amount_egp' => $resolvedWalletAmountEgp,
                'gateway_amount_egp' => $gatewayAmountEgp,
                'exchange_rate_snapshot' => $exchangeRate,
                'status' => SubscriptionPayment::STATUS_COMPLETED,
                'payment_method' => $paymentMethod ?? 'wallet',
                'currency_code' => $currencyCode,
                'price_source' => 'default',
                'tax' => 0,
                'final_amount' => $totalAmount,
                'paid_at' => now(),
            ]);

            if ($walletAmount > 0) {
                $debitEgp = $resolvedWalletAmountEgp;
                WalletService::debitWallet(
                    $user->id,
                    $debitEgp,
                    'subscription',
                    'Subscription renewal payment for plan: '.($subscription->plan ? $subscription->plan->name : 'plan'),
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
     * Cancel a subscription and any associated queued child subscriptions
     */
    public function cancelSubscription(Subscription $subscription, ?string $reason = null, bool $immediate = false): bool
    {
        if ($immediate) {
            $subscription->status = Subscription::STATUS_CANCELLED;
        }

        $result = $subscription->cancel($reason);

        // Cascade cancellation to any queued child subscriptions linked to this parent
        Subscription::where('parent_subscription_id', $subscription->id)
            ->where('status', Subscription::STATUS_PENDING)
            ->update([
                'status' => Subscription::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => 'Parent subscription was cancelled: '.($reason ?? 'No reason provided'),
            ]);

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
     * @return array{wallet_amount: float, gateway_amount: float, wallet_amount_egp: float, gateway_amount_egp: float}
     */
    public function walletAndGatewayPayment(User $user, SubscriptionPlan $plan, float $totalAmount, bool $useWallet, string $currencyCode = 'EGP'): array
    {
        $currencyCode = strtoupper($currencyCode ?: 'EGP');
        $conversion = app(CurrencyConversionService::class);
        $totalEgp = $conversion->convertToEgp($totalAmount, $currencyCode);

        $walletEgp = 0.0;
        $gatewayEgp = $totalEgp;

        if ($useWallet && $user->wallet_balance > 0) {
            $walletEgp = (float) min($user->wallet_balance, $totalEgp);
            $gatewayEgp = $totalEgp - $walletEgp;
        }

        $walletLocal = $conversion->convertFromEgp($walletEgp, $currencyCode);
        $gatewayLocal = round($totalAmount - $walletLocal, 2);

        return [
            'wallet_amount' => round($walletLocal, 2),
            'gateway_amount' => $gatewayLocal,
            'wallet_amount_egp' => round($walletEgp, 2),
            'gateway_amount_egp' => round($gatewayEgp, 2),
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
     * Resolve the subscription that should represent the user's current state
     * in overview surfaces. An active entitlement wins; otherwise expose the
     * next queued plan or the latest request awaiting admin approval.
     */
    public function getPrimaryVisibleSubscription(User $user): ?Subscription
    {
        $active = $this->getActiveSubscription($user);
        if ($active !== null) {
            return $active;
        }

        $futureActive = Subscription::forUser($user->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', now())
            ->with('plan')
            ->orderBy('starts_at')
            ->first();

        if ($futureActive !== null) {
            return $futureActive;
        }

        $queued = Subscription::forUser($user->id)
            ->where('status', Subscription::STATUS_PENDING)
            ->with('plan')
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();

        if ($queued !== null) {
            return $queued;
        }

        return Subscription::forUser($user->id)
            ->where('status', Subscription::STATUS_PENDING_APPROVAL)
            ->with('plan')
            ->latest('id')
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

        if (! $needsSync) {
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

                if (! $hasQueued && $subscription->auto_renew && $subscription->plan) {
                    $localPrice = (float) ($subscription->locked_price ?? $subscription->plan->price);
                    $currency = strtoupper((string) ($subscription->locked_currency ?? 'EGP'));
                    $priceEgp = app(CurrencyConversionService::class)->convertToEgp($localPrice, $currency);

                    if ($user->wallet_balance >= $priceEgp) {
                        try {
                            $this->renewWithPayment($user, $subscription, 'wallet', $localPrice, 0, $localPrice, $currency, $priceEgp);

                            Log::info('Subscription auto-renewed via wallet (Lazy Eval)', [
                                'subscription_id' => $subscription->id,
                                'user_id' => $user->id,
                                'amount' => $localPrice,
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

            $hasOtherActive = Subscription::where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->lockForUpdate()
                ->exists();

            foreach ($pendingToActivate as $sub) {
                if ($hasOtherActive) {
                    break;
                }

                $sub->status = Subscription::STATUS_ACTIVE;
                $sub->save();
                $hasOtherActive = true;

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

        if (! $subscription) {
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

        Subscription::with(['user', 'plan'])
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->chunkById(100, function ($expiredSubscriptions) use (&$count): void {
                foreach ($expiredSubscriptions as $subscription) {
                    DB::transaction(function () use ($subscription, &$count) {
                        $lockedSub = Subscription::where('id', $subscription->id)
                            ->where('status', Subscription::STATUS_ACTIVE)
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedSub) {
                            return;
                        }

                        if ($lockedSub->auto_renew && $lockedSub->plan) {
                            $user = $lockedSub->user;
                            $plan = $lockedSub->plan;
                            $localPrice = (float) ($lockedSub->locked_price ?? $plan->price);
                            $currency = strtoupper((string) ($lockedSub->locked_currency ?? 'EGP'));
                            $priceEgp = app(CurrencyConversionService::class)->convertToEgp($localPrice, $currency);

                            if ($user && $user->wallet_balance >= $priceEgp) {
                                try {
                                    $this->renewWithPayment($user, $lockedSub, 'wallet', $localPrice, 0, $localPrice, $currency, $priceEgp);

                                    Log::info('Subscription auto-renewed via wallet', [
                                        'subscription_id' => $lockedSub->id,
                                        'user_id' => $user->id,
                                        'amount' => $localPrice,
                                    ]);

                                    return;
                                } catch (\Throwable $e) {
                                    Log::warning('Auto-renewal failed, marking as expired', [
                                        'subscription_id' => $lockedSub->id,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }

                        $lockedSub->status = Subscription::STATUS_EXPIRED;
                        $lockedSub->save();
                        $count++;

                        Log::info('Subscription marked as expired', [
                            'subscription_id' => $lockedSub->id,
                            'user_id' => $lockedSub->user_id,
                        ]);
                    });
                }
            });

        return $count;
    }

    private static array $hasSubscriptionColumnCache = [];

    private static function hasSubscriptionColumn(string $column): bool
    {
        if (! array_key_exists($column, self::$hasSubscriptionColumnCache)) {
            self::$hasSubscriptionColumnCache[$column] = \Illuminate\Support\Facades\Schema::hasColumn('subscriptions', $column);
        }

        return self::$hasSubscriptionColumnCache[$column];
    }

    /**
     * Get subscriptions needing expiry notification for dynamic days
     */
    public function getSubscriptionsForNotificationDays(int $days): LazyCollection
    {
        $column = "notified_{$days}_days";
        if (! self::hasSubscriptionColumn($column)) {
            return LazyCollection::make();
        }

        return Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays($days))
            ->where($column, false)
            ->with(['user', 'plan'])
            ->lazyById(100);
    }

    /**
     * Mark a subscription as notified for a specific threshold
     */
    public function markNotifiedDynamic(Subscription $subscription, int $thresholdDays): void
    {
        $field = "notified_{$thresholdDays}_days";

        if (self::hasSubscriptionColumn($field)) {
            $subscription->{$field} = true;
            $subscription->updateQuietly([$field => true]);
        } elseif (self::hasSubscriptionColumn('notified_intervals')) {
            $intervals = $subscription->notified_intervals ?? [];
            if (! in_array($thresholdDays, $intervals)) {
                $intervals[] = $thresholdDays;
                $subscription->notified_intervals = $intervals;
                $subscription->updateQuietly(['notified_intervals' => $intervals]);
            }
        }
    }

    /**
     * Update user subscription settings (auto-renew toggle)
     */
    public function updateUserSettings(User $user, array $settings): ?Subscription
    {
        $subscription = $this->getActiveSubscription($user);

        if (! $subscription) {
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
