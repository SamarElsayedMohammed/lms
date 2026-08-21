<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PromoQuotaExceededException;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionPromoService
{
    public const RESERVATION_EXPIRY_HOURS = 4;

    public function __construct(
        protected PricingService $pricingService
    ) {}

    /**
     * Canonical promo code normalization.
     */
    public static function normalizeCode(?string $code): string
    {
        if ($code === null) {
            return '';
        }
        return strtoupper(trim((string) $code));
    }

    /**
     * Validate a promo code against a subscription plan, user, and country context.
     *
     * @return array{
     *   valid: bool,
     *   message: string,
     *   promo?: PromoCode,
     *   promo_code?: string,
     *   discount_type?: string,
     *   discount_value?: float,
     *   discount_percent?: float,
     *   discount_amount?: float,
     *   original_amount?: float,
     *   total_amount?: float,
     *   currency?: string,
     *   country_code?: string,
     *   price_source?: string
     * }
     */
    public function validatePromo(string $rawCode, int $planId, ?User $user = null, ?string $countryCode = null): array
    {
        $normalizedCode = self::normalizeCode($rawCode);
        if ($normalizedCode === '') {
            return [
                'valid' => false,
                'message' => 'كود الخصم غير صالح.',
            ];
        }

        // Lazy cleanup of expired reservations so quota is kept fresh
        $this->reclaimExpiredReservations();

        /** @var PromoCode|null $promo */
        $promo = PromoCode::where(function ($q) use ($normalizedCode) {
            $q->where('promo_code', $normalizedCode)
              ->orWhereRaw('UPPER(promo_code) = ?', [$normalizedCode]);
        })
        ->where('status', 1)
        ->first();

        if (! $promo) {
            return [
                'valid' => false,
                'message' => 'كود الخصم غير صحيح أو غير مفعل.',
            ];
        }

        // Date validity check: end_date is valid through end of day
        if ($promo->start_date && $promo->start_date->copy()->startOfDay()->isFuture()) {
            return [
                'valid' => false,
                'message' => 'كود الخصم لم يبدأ بعد.',
            ];
        }

        if ($promo->end_date && $promo->end_date->copy()->endOfDay()->isPast()) {
            return [
                'valid' => false,
                'message' => 'كود الخصم منتهي الصلاحية.',
            ];
        }

        // Plan restrictions
        if ($promo->subscriptionPlans()->exists()) {
            $isPlanAllowed = $promo->subscriptionPlans()->where('subscription_plans.id', $planId)->exists();
            if (! $isPlanAllowed) {
                return [
                    'valid' => false,
                    'message' => 'كود الخصم غير صالح لهذه الباقة.',
                ];
            }
        }

        // Global quota check
        if ($promo->no_of_users !== null) {
            $activeCount = $this->getActiveUsageCount($promo, $normalizedCode);
            if ($activeCount >= (int) $promo->no_of_users) {
                return [
                    'valid' => false,
                    'message' => 'كود الخصم وصل للحد الأقصى من المستخدمين.',
                ];
            }
        }

        // Per-user usage check
        if ($user !== null) {
            $userCheck = $this->checkUserEligibility($promo, $user->id, $normalizedCode);
            if (! $userCheck['allowed']) {
                return [
                    'valid' => false,
                    'message' => $userCheck['message'],
                ];
            }
        }

        // Resolve plan and localized pricing
        $plan = SubscriptionPlan::find($planId);
        if (! $plan) {
            return [
                'valid' => false,
                'message' => 'الباقة المحددة غير موجودة.',
            ];
        }

        $countryPricing = $this->pricingService->getPriceForCountry($plan, $countryCode);
        $originalAmount = (float) $countryPricing['price'];
        $resolvedCurrency = strtoupper($countryPricing['currency_code'] ?? 'EGP');

        $discountVal = (float) $promo->discount;
        $discountType = $promo->discount_type;

        if ($discountType === 'percentage') {
            $safePercent = max(0.0, min($discountVal, 100.0));
            $discountAmount = round($originalAmount * ($safePercent / 100.0), 2);
            $discountPercent = $safePercent;
        } else {
            $safeFixed = max(0.0, $discountVal);
            $discountAmount = $this->pricingService->convertFromEgp($safeFixed, $resolvedCurrency);
            $discountAmount = min($discountAmount, $originalAmount);
            $discountPercent = $originalAmount > 0 ? round(($discountAmount / $originalAmount) * 100, 2) : 0.0;
        }

        $totalAmount = max(round($originalAmount - $discountAmount, 2), 0.0);

        return [
            'valid' => true,
            'message' => $promo->message ?: 'كود الخصم صالح',
            'promo' => $promo,
            'promo_code' => $promo->promo_code,
            'discount_type' => $discountType,
            'discount_value' => $discountVal,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'original_amount' => $originalAmount,
            'total_amount' => $totalAmount,
            'currency' => $resolvedCurrency,
            'country_code' => $countryCode ?? '',
            'price_source' => $countryPricing['price_source'] ?? 'default',
        ];
    }

    /**
     * Get active usage count (consumed + active unexpired reservations)
     */
    public function getActiveUsageCount(PromoCode $promo, ?string $normalizedCode = null): int
    {
        $code = $normalizedCode ?: self::normalizeCode($promo->promo_code);
        $cutoff = now()->subHours(self::RESERVATION_EXPIRY_HOURS);

        $redemptionActive = PromoRedemption::where(function ($q) use ($promo, $code) {
            $q->where('promo_code_id', $promo->id)
              ->orWhere('promo_code', $code)
              ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
        })->where(function ($q) use ($cutoff) {
            $q->where('status', PromoRedemption::STATUS_CONSUMED)
              ->orWhere(function ($rq) use ($cutoff) {
                  $rq->where('status', PromoRedemption::STATUS_RESERVED)
                     ->where('reserved_at', '>=', $cutoff);
              });
        })->count();

        // Count unmigrated legacy completed subscription payments (not having a promo_redemptions record)
        $unlinkedCompletedPayments = SubscriptionPayment::where(function ($q) use ($code) {
            $q->where('promo_code', $code)
              ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
        })->where('status', SubscriptionPayment::STATUS_COMPLETED)
          ->whereNotIn('id', function ($sub) {
              $sub->select('subscription_payment_id')->from('promo_redemptions')->whereNotNull('subscription_payment_id');
          })->count();

        // Count unmigrated legacy pending subscription payments
        $unlinkedPendingPayments = SubscriptionPayment::where(function ($q) use ($code) {
            $q->where('promo_code', $code)
              ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
        })->where('status', SubscriptionPayment::STATUS_PENDING)
          ->where('created_at', '>=', $cutoff)
          ->whereNotIn('id', function ($sub) {
              $sub->select('subscription_payment_id')->from('promo_redemptions')->whereNotNull('subscription_payment_id');
          })->count();

        return $redemptionActive + $unlinkedCompletedPayments + $unlinkedPendingPayments;
    }

    /**
     * Check if a specific user is eligible to use this promo code based on repeat rules.
     */
    public function checkUserEligibility(PromoCode $promo, int $userId, ?string $normalizedCode = null): array
    {
        $code = $normalizedCode ?: self::normalizeCode($promo->promo_code);
        $cutoff = now()->subHours(self::RESERVATION_EXPIRY_HOURS);

        // Count completed & active reservations for this user via PromoRedemption
        $userRedemptions = PromoRedemption::where('user_id', $userId)
            ->where(function ($q) use ($promo, $code) {
                $q->where('promo_code_id', $promo->id)
                  ->orWhere('promo_code', $code)
                  ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
            })
            ->where(function ($q) use ($cutoff) {
                $q->where('status', PromoRedemption::STATUS_CONSUMED)
                  ->orWhere(function ($rq) use ($cutoff) {
                      $rq->where('status', PromoRedemption::STATUS_RESERVED)
                         ->where('reserved_at', '>=', $cutoff);
                  });
            })
            ->count();

        // Count unmigrated payments for this user not yet in promo_redemptions
        $unlinkedUserCompleted = SubscriptionPayment::where('user_id', $userId)
            ->where(function ($q) use ($code) {
                $q->where('promo_code', $code)
                  ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
            })
            ->where('status', SubscriptionPayment::STATUS_COMPLETED)
            ->whereNotIn('id', function ($sub) {
                $sub->select('subscription_payment_id')->from('promo_redemptions')->whereNotNull('subscription_payment_id');
            })
            ->count();

        $unlinkedUserPending = SubscriptionPayment::where('user_id', $userId)
            ->where(function ($q) use ($code) {
                $q->where('promo_code', $code)
                  ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
            })
            ->where('status', SubscriptionPayment::STATUS_PENDING)
            ->where('created_at', '>=', $cutoff)
            ->whereNotIn('id', function ($sub) {
                $sub->select('subscription_payment_id')->from('promo_redemptions')->whereNotNull('subscription_payment_id');
            })
            ->count();

        $totalUserUsages = $userRedemptions + $unlinkedUserCompleted + $unlinkedUserPending;

        if (! $promo->repeat_usage) {
            if ($totalUserUsages >= 1) {
                return [
                    'allowed' => false,
                    'message' => 'لقد استخدمت كود الخصم هذا من قبل.',
                ];
            }
        } else {
            $maxRepeat = (int) $promo->no_of_repeat_usage;
            if ($maxRepeat > 0 && $totalUserUsages >= $maxRepeat) {
                return [
                    'allowed' => false,
                    'message' => 'لقد وصلت للحد الأقصى المسموح لاستخدام هذا الكود.',
                ];
            }
        }

        return [
            'allowed' => true,
            'message' => '',
        ];
    }

    /**
     * Atomically reserve promo code quota and create a PromoRedemption record.
     *
     * @return array{
     *   success: bool,
     *   message?: string,
     *   promo?: PromoCode,
     *   redemption?: PromoRedemption,
     *   discount_amount?: float,
     *   original_amount?: float,
     *   final_amount?: float,
     *   currency?: string
     * }
     */
    public function reservePromo(string $rawCode, int $userId, int $planId, ?string $countryCode = null): array
    {
        $normalizedCode = self::normalizeCode($rawCode);
        if ($normalizedCode === '') {
            return ['success' => false, 'message' => 'كود الخصم غير صالح.'];
        }

        try {
            return DB::transaction(function () use ($normalizedCode, $userId, $planId, $countryCode) {
                /** @var PromoCode|null $promo */
                $promo = PromoCode::where(function ($q) use ($normalizedCode) {
                    $q->where('promo_code', $normalizedCode)
                      ->orWhereRaw('UPPER(promo_code) = ?', [$normalizedCode]);
                })
                ->where('status', 1)
                ->lockForUpdate()
                ->first();

                if (! $promo) {
                    return ['success' => false, 'message' => 'كود الخصم غير صحيح أو غير مفعل.'];
                }

                // Date checks
                if ($promo->start_date && $promo->start_date->copy()->startOfDay()->isFuture()) {
                    return ['success' => false, 'message' => 'كود الخصم لم يبدأ بعد.'];
                }

                if ($promo->end_date && $promo->end_date->copy()->endOfDay()->isPast()) {
                    return ['success' => false, 'message' => 'كود الخصم منتهي الصلاحية.'];
                }

                // Plan checks
                if ($promo->subscriptionPlans()->exists()) {
                    $isPlanAllowed = $promo->subscriptionPlans()->where('subscription_plans.id', $planId)->exists();
                    if (! $isPlanAllowed) {
                        return ['success' => false, 'message' => 'كود الخصم غير صالح لهذه الباقة.'];
                    }
                }

                // Global quota check
                if ($promo->no_of_users !== null) {
                    $activeCount = $this->getActiveUsageCount($promo, $normalizedCode);
                    if ($activeCount >= (int) $promo->no_of_users) {
                        throw new PromoQuotaExceededException('كوبون الخصم استنفذ الحد الأقصى للاستخدام');
                    }
                }

                // Per-user check
                $userCheck = $this->checkUserEligibility($promo, $userId, $normalizedCode);
                if (! $userCheck['allowed']) {
                    return ['success' => false, 'message' => $userCheck['message']];
                }

                // Calculate pricing
                $plan = SubscriptionPlan::findOrFail($planId);
                $countryPricing = $this->pricingService->getPriceForCountry($plan, $countryCode);
                $originalAmount = (float) $countryPricing['price'];
                $resolvedCurrency = strtoupper($countryPricing['currency_code'] ?? 'EGP');

                $discountVal = (float) $promo->discount;
                $discountType = $promo->discount_type;

                if ($discountType === 'percentage') {
                    $safePercent = max(0.0, min($discountVal, 100.0));
                    $discountAmount = round($originalAmount * ($safePercent / 100.0), 2);
                } else {
                    $safeFixed = max(0.0, $discountVal);
                    $discountAmount = $this->pricingService->convertFromEgp($safeFixed, $resolvedCurrency);
                    $discountAmount = min($discountAmount, $originalAmount);
                }

                $finalAmount = max(round($originalAmount - $discountAmount, 2), 0.0);

                // Create durable redemption record in reserved state
                $redemption = PromoRedemption::create([
                    'promo_code_id' => $promo->id,
                    'promo_code' => $promo->promo_code,
                    'user_id' => $userId,
                    'status' => PromoRedemption::STATUS_RESERVED,
                    'currency' => $resolvedCurrency,
                    'original_amount' => $originalAmount,
                    'discount_amount' => $discountAmount,
                    'final_amount' => $finalAmount,
                    'discount_type_snapshot' => $discountType,
                    'discount_value_snapshot' => $discountVal,
                    'reserved_at' => now(),
                ]);

                return [
                    'success' => true,
                    'promo' => $promo,
                    'redemption' => $redemption,
                    'original_amount' => $originalAmount,
                    'discount_amount' => $discountAmount,
                    'final_amount' => $finalAmount,
                    'currency' => $resolvedCurrency,
                ];
            });
        } catch (PromoQuotaExceededException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Atomically consume a promo redemption (on payment approval or instant gateway success).
     */
    public function consumePromo(int $paymentId, ?string $promoCode = null): bool
    {
        return DB::transaction(function () use ($paymentId, $promoCode) {
            $redemption = PromoRedemption::where('subscription_payment_id', $paymentId)
                ->where('status', PromoRedemption::STATUS_RESERVED)
                ->lockForUpdate()
                ->first();

            if ($redemption) {
                $redemption->markAsConsumed();
                return true;
            }

            // If no linked redemption by payment_id, find by code and user
            if ($promoCode) {
                $payment = SubscriptionPayment::find($paymentId);
                if ($payment) {
                    $code = self::normalizeCode($promoCode);
                    $redemption = PromoRedemption::where('user_id', $payment->user_id)
                        ->where(function ($q) use ($code) {
                            $q->where('promo_code', $code)
                              ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
                        })
                        ->where('status', PromoRedemption::STATUS_RESERVED)
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();

                    if ($redemption) {
                        $redemption->subscription_id = $payment->subscription_id;
                        $redemption->subscription_payment_id = $payment->id;
                        $redemption->markAsConsumed();
                        return true;
                    }

                    // Create fresh consumed redemption if none existed
                    $promo = PromoCode::where('promo_code', $code)->first();
                    PromoRedemption::create([
                        'promo_code_id' => $promo?->id,
                        'promo_code' => $promoCode,
                        'user_id' => $payment->user_id,
                        'subscription_id' => $payment->subscription_id,
                        'subscription_payment_id' => $payment->id,
                        'status' => PromoRedemption::STATUS_CONSUMED,
                        'currency' => $payment->currency_code ?: 'EGP',
                        'original_amount' => $payment->original_amount ?? $payment->amount,
                        'discount_amount' => $payment->discount_amount ?? 0,
                        'final_amount' => $payment->final_amount ?? $payment->amount,
                        'discount_type_snapshot' => $promo?->discount_type,
                        'discount_value_snapshot' => $promo?->discount,
                        'reserved_at' => $payment->created_at,
                        'consumed_at' => now(),
                    ]);
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Atomically release a promo reservation (e.g. on failed payment, abandoned session, or admin rejection).
     */
    public function releasePromo(?string $promoCode, ?int $paymentId = null): bool
    {
        $code = self::normalizeCode($promoCode);

        return DB::transaction(function () use ($code, $paymentId) {
            if ($paymentId) {
                $redemptions = PromoRedemption::where('subscription_payment_id', $paymentId)
                    ->where('status', PromoRedemption::STATUS_RESERVED)
                    ->lockForUpdate()
                    ->get();

                foreach ($redemptions as $r) {
                    $r->markAsReleased();
                }

                if ($redemptions->isNotEmpty()) {
                    return true;
                }
            }

            if ($code !== '') {
                $redemption = PromoRedemption::where(function ($q) use ($code) {
                    $q->where('promo_code', $code)
                      ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
                })
                ->where('status', PromoRedemption::STATUS_RESERVED)
                ->latest('id')
                ->lockForUpdate()
                ->first();

                if ($redemption) {
                    $redemption->markAsReleased();
                }
            }

            return true;
        });
    }

    /**
     * Sweep and reclaim expired pending reservations older than RESERVATION_EXPIRY_HOURS.
     */
    public function reclaimExpiredReservations(?int $hours = null): int
    {
        $cutoff = now()->subHours($hours ?? self::RESERVATION_EXPIRY_HOURS);

        // 1. Reclaim expired PromoRedemption records
        $expiredRedemptionsCount = 0;
        PromoRedemption::where('status', PromoRedemption::STATUS_RESERVED)
            ->where('reserved_at', '<', $cutoff)
            ->chunkById(100, function ($expiredRedemptions) use (&$expiredRedemptionsCount) {
                foreach ($expiredRedemptions as $r) {
                    $r->markAsExpired();
                    $expiredRedemptionsCount++;
                }
            });

        // 2. Expire old pending payments
        $reclaimedCount = 0;
        SubscriptionPayment::where('status', SubscriptionPayment::STATUS_PENDING)
            ->whereNotNull('promo_code')
            ->where('promo_code', '!=', '')
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($expiredPayments) use (&$reclaimedCount) {
                foreach ($expiredPayments as $payment) {
                    DB::transaction(function () use ($payment, &$reclaimedCount) {
                        $lockedPayment = SubscriptionPayment::where('id', $payment->id)
                            ->where('status', SubscriptionPayment::STATUS_PENDING)
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedPayment) {
                            return;
                        }

                        $lockedPayment->status = SubscriptionPayment::STATUS_FAILED;
                        $lockedPayment->admin_notes = 'Payment intent expired automatically';
                        $lockedPayment->save();

                        if ($lockedPayment->subscription && in_array($lockedPayment->subscription->status, [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL], true)) {
                            $lockedPayment->subscription->status = Subscription::STATUS_CANCELLED;
                            $lockedPayment->subscription->save();
                        }

                        $this->releasePromo($lockedPayment->promo_code, $lockedPayment->id);
                        $reclaimedCount++;
                    });
                }
            });

        return $reclaimedCount + $expiredRedemptionsCount;
    }

    /**
     * Reconcile and backfill legacy completed SubscriptionPayment and Order records into PromoRedemption.
     *
     * @return array{subscription_payments_backfilled: int, orders_backfilled: int}
     */
    public function backfillHistoricalRedemptions(): array
    {
        $paymentsBackfilled = 0;
        $ordersBackfilled = 0;

        // 1. Backfill legacy completed subscription payments
        SubscriptionPayment::where('status', SubscriptionPayment::STATUS_COMPLETED)
            ->whereNotNull('promo_code')
            ->where('promo_code', '!=', '')
            ->whereNotIn('id', function ($q) {
                $q->select('subscription_payment_id')->from('promo_redemptions')->whereNotNull('subscription_payment_id');
            })
            ->chunkById(100, function ($unlinkedPayments) use (&$paymentsBackfilled) {
                foreach ($unlinkedPayments as $payment) {
                    $code = self::normalizeCode($payment->promo_code);
                    $promo = PromoCode::where(function ($q) use ($code) {
                        $q->where('promo_code', $code)
                          ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
                    })->first();

                    PromoRedemption::create([
                        'promo_code_id' => $promo?->id,
                        'promo_code' => $payment->promo_code,
                        'user_id' => $payment->user_id,
                        'subscription_id' => $payment->subscription_id,
                        'subscription_payment_id' => $payment->id,
                        'status' => PromoRedemption::STATUS_CONSUMED,
                        'currency' => $payment->currency_code ?: 'EGP',
                        'original_amount' => (float) ($payment->original_amount ?? $payment->amount),
                        'discount_amount' => (float) ($payment->discount_amount ?? 0),
                        'final_amount' => (float) ($payment->final_amount ?? $payment->amount),
                        'discount_type_snapshot' => $promo?->discount_type,
                        'discount_value_snapshot' => $promo?->discount,
                        'reserved_at' => $payment->created_at,
                        'consumed_at' => $payment->paid_at ?? $payment->created_at,
                    ]);
                    $paymentsBackfilled++;
                }
            });

        // 2. Backfill legacy completed orders
        \App\Models\Order::where('status', 'completed')
            ->where(function ($q) {
                $q->whereNotNull('promo_code_id')
                  ->orWhere(function ($oq) {
                      $oq->whereNotNull('promo_code')->where('promo_code', '!=', '');
                  });
            })
            ->whereNotIn('id', function ($q) {
                $q->select('order_id')->from('promo_redemptions')->whereNotNull('order_id');
            })
            ->chunkById(100, function ($unlinkedOrders) use (&$ordersBackfilled) {
                foreach ($unlinkedOrders as $order) {
                    $promo = null;
                    if ($order->promo_code_id) {
                        $promo = PromoCode::find($order->promo_code_id);
                    }
                    if (! $promo && $order->promo_code) {
                        $code = self::normalizeCode($order->promo_code);
                        $promo = PromoCode::where(function ($q) use ($code) {
                            $q->where('promo_code', $code)
                              ->orWhereRaw('UPPER(promo_code) = ?', [$code]);
                        })->first();
                    }

                    $orderCode = $order->promo_code ?: ($promo ? $promo->promo_code : 'PROMO');
                    $originalAmount = (float) ($order->subtotal ?? $order->total ?? 0);
                    $discountAmount = (float) ($order->discount ?? 0);
                    $finalAmount = (float) ($order->total ?? ($originalAmount - $discountAmount));

                    PromoRedemption::create([
                        'promo_code_id' => $promo?->id,
                        'promo_code' => $orderCode,
                        'user_id' => $order->user_id,
                        'order_id' => $order->id,
                        'status' => PromoRedemption::STATUS_CONSUMED,
                        'currency' => $order->currency ?? 'EGP',
                        'original_amount' => $originalAmount,
                        'discount_amount' => $discountAmount,
                        'final_amount' => $finalAmount,
                        'discount_type_snapshot' => $promo?->discount_type,
                        'discount_value_snapshot' => $promo?->discount,
                        'reserved_at' => $order->created_at,
                        'consumed_at' => $order->created_at,
                    ]);
                    $ordersBackfilled++;
                }
            });

        return [
            'subscription_payments_backfilled' => $paymentsBackfilled,
            'orders_backfilled' => $ordersBackfilled,
        ];
    }
}
