<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\Reports\ReportMoneySql;
use App\Services\Reports\ReportingPeriod;
use App\Services\Reports\ReportingPeriodService;
use App\Services\Reports\SubscriptionLifecycleQuery;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class SubscriptionReportService
{
    /**
     * Country metadata mapping with Arabic/English names and flag emojis.
     */
    private const COUNTRY_META = [
        'EG' => ['name_ar' => 'مصر', 'name_en' => 'Egypt', 'flag' => '🇪🇬', 'color' => '#e50914'],
        'SA' => ['name_ar' => 'السعودية', 'name_en' => 'Saudi Arabia', 'flag' => '🇸🇦', 'color' => '#10b981'],
        'AE' => ['name_ar' => 'الإمارات', 'name_en' => 'UAE', 'flag' => '🇦🇪', 'color' => '#f59e0b'],
        'KW' => ['name_ar' => 'الكويت', 'name_en' => 'Kuwait', 'flag' => '🇰🇼', 'color' => '#3b82f6'],
        'QA' => ['name_ar' => 'قطر', 'name_en' => 'Qatar', 'flag' => '🇶🇦', 'color' => '#8b5cf6'],
        'OM' => ['name_ar' => 'عمان', 'name_en' => 'Oman', 'flag' => '🇴🇲', 'color' => '#ec4899'],
        'BH' => ['name_ar' => 'البحرين', 'name_en' => 'Bahrain', 'flag' => '🇧🇭', 'color' => '#06b6d4'],
        'JO' => ['name_ar' => 'الأردن', 'name_en' => 'Jordan', 'flag' => '🇯🇴', 'color' => '#84cc16'],
        'UNASSIGNED' => ['name_ar' => 'غير محدد', 'name_en' => 'Unassigned', 'flag' => '🌐', 'color' => '#6b7280'],
    ];

    /**
     * Get Global Subscription Report summary, metrics, time series, and plan summaries.
     */
    public function getGlobalOverviewReport(array $filters): array
    {
        $period = $this->resolvePeriod($filters);
        $currentStart = $period->start;
        $currentEnd = $period->end;
        $prevStart = $period->previousStart;
        $prevEnd = $period->previousEnd;

        $paymentMethod = $filters['payment_method'] ?? null;
        $country = isset($filters['country']) ? strtoupper((string) $filters['country']) : null;
        $statusFilter = $filters['status'] ?? 'all';

        // 1. Current Period Metrics
        $currentPaymentsQuery = $this->getBasePaymentsQuery($currentStart, $currentEnd, $paymentMethod, $country, $statusFilter);
        $currentRevenueEgp = (float) (clone $currentPaymentsQuery)->sum(DB::raw(ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments')));
        $currentOrdersCount = (int) (clone $currentPaymentsQuery)->count();

        $lifecycle = $this->buildLifecycleMetrics($currentStart, $currentEnd, $statusFilter, $paymentMethod, $country);
        $currentSubscribersCount = $lifecycle['new_unique_subscribers'];
        $currentSubscriptionsCount = $lifecycle['subscription_records_started'];
        $currentActiveCount = $lifecycle['active_at_period_end'];
        $currentExpiredCount = $lifecycle['expired_events'];
        $currentCancelledCount = $lifecycle['cancelled_events'];
        $currentActiveRecords = $lifecycle['started_cohort_active_records'];
        $currentExpiredRecords = $lifecycle['started_cohort_expired_records'];
        $currentCancelledRecords = $lifecycle['started_cohort_cancelled_records'];
        $currentPendingRecords = $lifecycle['started_cohort_pending_records'];

        // 2. Previous Period Metrics (for comparisons)
        $prevPaymentsQuery = $this->getBasePaymentsQuery($prevStart, $prevEnd, $paymentMethod, $country, $statusFilter);
        $prevRevenueEgp = (float) (clone $prevPaymentsQuery)->sum(DB::raw(ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments')));
        $prevOrdersCount = (int) (clone $prevPaymentsQuery)->count();

        $prevSubsQuery = $this->getBaseSubscriptionsQuery($prevStart, $prevEnd, $statusFilter, $paymentMethod, $country);
        $prevLifecycle = $this->buildLifecycleMetrics($prevStart, $prevEnd, $statusFilter, $paymentMethod, $country);
        $prevSubscribersCount = $prevLifecycle['new_unique_subscribers'];
        $prevExpiredCount = $prevLifecycle['expired_events'];

        // 3. Comparisons Calculation
        $comparisons = [
            'revenue' => $this->buildComparisonMetric($currentRevenueEgp, $prevRevenueEgp),
            'orders' => $this->buildComparisonMetric($currentOrdersCount, $prevOrdersCount),
            'subscribers' => $this->buildComparisonMetric($currentSubscribersCount, $prevSubscribersCount),
            'expired' => $this->buildComparisonMetric($currentExpiredCount, $prevExpiredCount),
        ];

        // 4. Time Series Data (Revenue Overview Chart)
        $revenueSeries = $this->buildRevenueTimeSeries($currentStart, $currentEnd, $paymentMethod, $country, $statusFilter, $filters['preset'] ?? '30d');

        // 5. Status Distribution (Donut Chart)
        $statusDistribution = $this->buildStatusDistribution($currentStart, $currentEnd, $paymentMethod, $country, $statusFilter);

        // 6. Plan Summaries Grid
        $plans = $this->buildPlanSummaries($currentStart, $currentEnd, $paymentMethod, $country, $statusFilter);

        return [
            'summary' => [
                'total_plans' => count($plans),
                'total_revenue_egp' => round($currentRevenueEgp, 2),
                'total_orders' => $currentOrdersCount,
                'new_unique_subscribers' => $lifecycle['new_unique_subscribers'],
                'subscription_records_started' => $lifecycle['subscription_records_started'],
                'active_during_period' => $lifecycle['active_during_period'],
                'active_at_period_end' => $lifecycle['active_at_period_end'],
                'active_now' => $lifecycle['active_now'],
                'expired_events' => $lifecycle['expired_events'],
                'cancelled_events' => $lifecycle['cancelled_events'],
                'churned_subscribers' => null,
                'started_cohort_active_unique' => $lifecycle['started_cohort_active_unique'],
                'started_cohort_expired_unique' => $lifecycle['started_cohort_expired_unique'],
                'started_cohort_cancelled_unique' => $lifecycle['started_cohort_cancelled_unique'],
                'total_subscribers' => $currentSubscribersCount,
                'subscriptions_count' => $currentSubscriptionsCount,
                'total_active_subscribers' => $currentActiveCount,
                'total_expired_subscribers' => $currentExpiredCount,
                'total_cancelled_subscribers' => $currentCancelledCount,
                'total_active_subscription_records' => $currentActiveRecords,
                'total_expired_subscription_records' => $currentExpiredRecords,
                'total_cancelled_subscription_records' => $currentCancelledRecords,
                'total_pending_subscription_records' => $currentPendingRecords,
                // No suspended state exists in the subscription lifecycle yet.
                'total_suspended_subscribers' => null,
                'comparisons' => $comparisons,
            ],
            'revenue_series' => $revenueSeries,
            'status_distribution' => $statusDistribution,
            'plans' => $plans,
            'meta' => $this->buildMeta($period, $filters),
        ];
    }

    /**
     * Get Individual Subscription Plan Detail Report.
     */
    public function getPlanDetailReport(int $planId, array $filters): array
    {
        $plan = SubscriptionPlan::withTrashed()->find($planId);
        if (!$plan) {
            throw new \InvalidArgumentException('الباقة غير موجودة.');
        }

        $period = $this->resolvePeriod($filters);
        $currentStart = $period->start;
        $currentEnd = $period->end;
        $prevStart = $period->previousStart;
        $prevEnd = $period->previousEnd;

        $paymentMethod = $filters['payment_method'] ?? null;
        $country = isset($filters['country']) ? strtoupper((string) $filters['country']) : null;

        // Base plan queries
        $statusFilter = $filters['status'] ?? 'all';
        $currentPlanSubs = $this->getBaseSubscriptionsQuery($currentStart, $currentEnd, $statusFilter, $paymentMethod, $country)
            ->where('plan_id', $planId);

        $currentPlanPayments = SubscriptionPayment::whereHas('subscription', function ($q) use ($planId) {
            $q->withTrashed()->where('plan_id', $planId);
        })
        ->where('status', SubscriptionPayment::STATUS_COMPLETED);
        $this->applyPaymentDateRange($currentPlanPayments, $currentStart, $currentEnd);

        if ($paymentMethod) {
            $currentPlanPayments->where('payment_method', $paymentMethod);
        }
        if ($country) {
            $currentPlanPayments->where('resolved_country', $country);
        }
        if ($statusFilter !== 'all') {
            $currentPlanPayments->whereHas('subscription', fn ($q) => $q->withTrashed()->where('status', $statusFilter));
        }

        $lifecycle = $this->buildLifecycleMetrics($currentStart, $currentEnd, $statusFilter, $paymentMethod, $country, $planId);
        $totalStudents = $lifecycle['new_unique_subscribers'];
        $subscriptionsCount = $lifecycle['subscription_records_started'];
        $activeSubs = $lifecycle['active_at_period_end'];
        $expiredSubs = $lifecycle['expired_events'];
        $cancelledSubs = $lifecycle['cancelled_events'];
        $activeRecords = $lifecycle['started_cohort_active_records'];
        $expiredRecords = $lifecycle['started_cohort_expired_records'];
        $cancelledRecords = $lifecycle['started_cohort_cancelled_records'];
        $totalRevenueEgp = (float) (clone $currentPlanPayments)->sum(DB::raw(ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments')));

        // Previous plan metrics
        $prevPlanSubs = $this->getBaseSubscriptionsQuery($prevStart, $prevEnd, $statusFilter, $paymentMethod, $country)
            ->where('plan_id', $planId);
        $prevPlanPayments = SubscriptionPayment::whereHas('subscription', function ($q) use ($planId) {
            $q->withTrashed()->where('plan_id', $planId);
        })
        ->where('status', SubscriptionPayment::STATUS_COMPLETED);
        $this->applyPaymentDateRange($prevPlanPayments, $prevStart, $prevEnd);

        if ($paymentMethod) {
            $prevPlanPayments->where('payment_method', $paymentMethod);
        }
        if ($country) {
            $prevPlanPayments->where('resolved_country', $country);
        }
        if ($statusFilter !== 'all') {
            $prevPlanPayments->whereHas('subscription', fn ($q) => $q->withTrashed()->where('status', $statusFilter));
        }

        $prevStudents = (int) (clone $prevPlanSubs)->distinct('user_id')->count('user_id');
        $prevActive = (int) (clone $prevPlanSubs)->where('status', Subscription::STATUS_ACTIVE)
            ->distinct('user_id')->count('user_id');
        $prevExpired = (int) (clone $prevPlanSubs)->where('status', Subscription::STATUS_EXPIRED)
            ->distinct('user_id')->count('user_id');
        $prevRevenue = (float) (clone $prevPlanPayments)->sum(DB::raw(ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments')));

        $comparisons = [
            'students' => $this->buildComparisonMetric($totalStudents, $prevStudents),
            'active' => $this->buildComparisonMetric($activeSubs, $prevActive),
            'expired' => $this->buildComparisonMetric($expiredSubs, $prevExpired),
            'revenue' => $this->buildComparisonMetric($totalRevenueEgp, $prevRevenue),
        ];

        // Country breakdown table & distribution chart
        $countryBreakdown = $this->buildCountryBreakdown($planId, $currentStart, $currentEnd, $paymentMethod, $country, $statusFilter);

        // Monthly growth is a rolling 12-calendar-month series, independent of the KPI period.
        $monthlyGrowth = $this->buildMonthlyGrowth($planId, $paymentMethod, $country, $currentStart, $currentEnd);

        return [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->slug ?: (string) $plan->id,
            'badge_variant' => $this->resolveBadgeVariant($plan->name),
            'billing_cycle' => $plan->billing_cycle ?? 'سنوي',
            'status' => $plan->is_active ? 'active' : 'inactive',
            'catalog_price_egp' => (float) $plan->price,
            'summary' => [
                'total_students' => $totalStudents,
                'total_subscribers' => $totalStudents,
                'new_unique_subscribers' => $lifecycle['new_unique_subscribers'],
                'subscription_records_started' => $lifecycle['subscription_records_started'],
                'active_during_period' => $lifecycle['active_during_period'],
                'active_at_period_end' => $lifecycle['active_at_period_end'],
                'active_now' => $lifecycle['active_now'],
                'expired_events' => $lifecycle['expired_events'],
                'cancelled_events' => $lifecycle['cancelled_events'],
                'churned_subscribers' => null,
                'started_cohort_active_unique' => $lifecycle['started_cohort_active_unique'],
                'started_cohort_expired_unique' => $lifecycle['started_cohort_expired_unique'],
                'started_cohort_cancelled_unique' => $lifecycle['started_cohort_cancelled_unique'],
                'subscriptions_count' => $subscriptionsCount,
                'active_subscribers' => $activeSubs,
                'expired_subscribers' => $expiredSubs,
                'cancelled_subscribers' => $cancelledSubs,
                'active_subscription_records' => $activeRecords,
                'expired_subscription_records' => $expiredRecords,
                'cancelled_subscription_records' => $cancelledRecords,
                'total_revenue_egp' => round($totalRevenueEgp, 2),
                'comparisons' => $comparisons,
            ],
            'country_breakdown' => $countryBreakdown['table'],
            'country_totals' => $countryBreakdown['totals'],
            'country_distribution' => $countryBreakdown['distribution'],
            'monthly_growth' => $monthlyGrowth,
            'meta' => array_merge($this->buildMeta($period, $filters), [
                'monthly_growth_scope' => 'rolling_12_calendar_months',
            ]),
        ];
    }

    /**
     * Generate CSV export stream for global subscription report.
     */
    public function generateCsvContent(array $filters): string
    {
        $overview = $this->getGlobalOverviewReport($filters);
        $plans = $overview['plans'];

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create the CSV export stream.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'الرمز',
            'الباقة',
            'مشتركون جدد',
            'سجلات بدأت',
            'نشطون بنهاية الفترة',
            'انتهاء خلال الفترة',
            'إلغاء خلال الفترة',
            'إيرادات محصلة (ج.م)',
            'سعر الكتالوج الحالي (ج.م)',
            'الباقة نشطة',
            'الباقة محذوفة',
        ]);

        foreach ($plans as $p) {
            $code = $this->escapeCsvFormula((string) ($p['plan_code'] ?? 'plan'));
            $name = $this->escapeCsvFormula((string) $p['plan_name']);
            $total = $p['new_unique_subscribers'] ?? $p['subscribers_count'] ?? $p['total_subscribers'] ?? 0;
            $records = $p['subscription_records_started'] ?? $p['subscriptions_count'] ?? 0;
            $active = $p['active_at_period_end'] ?? $p['active_subscribers'];
            $expired = $p['expired_events'] ?? $p['expired_subscribers'] ?? 0;
            $cancelled = $p['cancelled_events'] ?? $p['cancelled_subscribers'] ?? 0;
            $revenue = number_format((float) $p['total_revenue_egp'], 2, '.', '');
            $catalog = number_format((float) ($p['catalog_price_egp'] ?? $p['price'] ?? 0), 2, '.', '');

            fputcsv($stream, [
                $code,
                $name,
                $total,
                $records,
                $active,
                $expired,
                $cancelled,
                $revenue,
                $catalog,
                !empty($p['is_active']) ? '1' : '0',
                !empty($p['is_deleted']) ? '1' : '0',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    private function escapeCsvFormula(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    // ── Internal Helpers ───────────────────────────────────────────────────

    private function resolvePeriod(array $filters): ReportingPeriod
    {
        return (new ReportingPeriodService())->resolve($filters);
    }

    /**
     * @return array<string, int|null>
     */
    private function buildLifecycleMetrics(
        Carbon $start,
        Carbon $end,
        string $status,
        ?string $paymentMethod,
        ?string $country,
        ?int $planId = null,
    ): array {
        $started = $this->subscriptionScope($status, $paymentMethod, $country, $planId);
        SubscriptionLifecycleQuery::startedInPeriod($started, $start, $end);

        $overlapping = $this->subscriptionScope($status, $paymentMethod, $country, $planId);
        SubscriptionLifecycleQuery::overlappingPeriod($overlapping, $start, $end);

        $activeAtEnd = $this->subscriptionScope($status, $paymentMethod, $country, $planId);
        SubscriptionLifecycleQuery::activeAt($activeAtEnd, $end);

        $activeNow = $this->subscriptionScope($status, $paymentMethod, $country, $planId);
        SubscriptionLifecycleQuery::activeAt($activeNow, Carbon::now((string) config('app.timezone', 'UTC')));

        $expiredEvents = $this->subscriptionScope($status, $paymentMethod, $country, $planId);
        SubscriptionLifecycleQuery::expiredEvents($expiredEvents, $start, $end);

        $cancelledEvents = $this->subscriptionScope($status, $paymentMethod, $country, $planId);
        SubscriptionLifecycleQuery::cancelledEvents($cancelledEvents, $start, $end);

        return [
            'new_unique_subscribers' => $this->uniqueUserCount($started),
            'subscription_records_started' => (int) (clone $started)->count(),
            'active_during_period' => $this->uniqueUserCount($overlapping),
            'active_at_period_end' => $this->uniqueUserCount($activeAtEnd),
            'active_now' => $this->uniqueUserCount($activeNow),
            'expired_events' => $this->uniqueUserCount($expiredEvents),
            'cancelled_events' => $this->uniqueUserCount($cancelledEvents),
            'started_cohort_active_unique' => $this->uniqueUserCount((clone $started)->where('status', Subscription::STATUS_ACTIVE)),
            'started_cohort_expired_unique' => $this->uniqueUserCount((clone $started)->where('status', Subscription::STATUS_EXPIRED)),
            'started_cohort_cancelled_unique' => $this->uniqueUserCount((clone $started)->where('status', Subscription::STATUS_CANCELLED)),
            'started_cohort_active_records' => (int) (clone $started)->where('status', Subscription::STATUS_ACTIVE)->count(),
            'started_cohort_expired_records' => (int) (clone $started)->where('status', Subscription::STATUS_EXPIRED)->count(),
            'started_cohort_cancelled_records' => (int) (clone $started)->where('status', Subscription::STATUS_CANCELLED)->count(),
            'started_cohort_pending_records' => (int) (clone $started)
                ->whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])
                ->count(),
        ];
    }

    /**
     * @return Builder<Subscription>
     */
    private function subscriptionScope(string $status, ?string $paymentMethod, ?string $country, ?int $planId = null): Builder
    {
        $query = Subscription::query();
        if ($planId !== null) {
            $query->where('plan_id', $planId);
        }
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $this->applySubscriptionPaymentFilters($query, $paymentMethod, $country);

        return $query;
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    private function uniqueUserCount(Builder $query): int
    {
        return (int) (clone $query)->distinct('user_id')->count('user_id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function buildMeta(ReportingPeriod $period, array $filters): array
    {
        return [
            'currency' => 'EGP',
            'timezone' => $period->timezone,
            'generated_at' => Carbon::now($period->timezone)->toIso8601String(),
            'data_scope' => 'subscription_lifecycle_and_settled_payments',
            'current_period' => $period->currentIso(),
            'previous_period' => $period->previousIso(),
            'applied_filters' => array_filter($filters, static fn ($value) => $value !== null && $value !== ''),
            'metric_grains' => $this->metricGrainMeta(),
        ];
    }

    private function getBasePaymentsQuery(Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country, string $status = 'all')
    {
        $q = SubscriptionPayment::where('status', SubscriptionPayment::STATUS_COMPLETED)
            ->whereRaw($this->getPaymentDateSql() . ' BETWEEN ? AND ?', [$start, $end]);

        if ($paymentMethod) {
            $q->where('payment_method', $paymentMethod);
        }
        if ($country) {
            $q->where('resolved_country', $country);
        }
        if ($status !== 'all') {
            $q->whereHas('subscription', fn ($sq) => $sq->withTrashed()->where('status', $status));
        }

        return $q;
    }

    private function getBaseSubscriptionsQuery(Carbon $start, Carbon $end, string $status, ?string $paymentMethod, ?string $country)
    {
        $q = Subscription::whereBetween('starts_at', [$start, $end]);

        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($paymentMethod || $country) {
            $q->whereHas('payments', function ($pq) use ($paymentMethod, $country) {
                $pq->where('status', SubscriptionPayment::STATUS_COMPLETED);
                if ($paymentMethod) {
                    $pq->where('payment_method', $paymentMethod);
                }
                if ($country) {
                    $pq->where('resolved_country', $country);
                }
            });
        }

        return $q;
    }

    private function applySubscriptionPaymentFilters($query, ?string $paymentMethod, ?string $country)
    {
        if (!$paymentMethod && !$country) {
            return $query;
        }

        return $query->whereHas('payments', function ($paymentQuery) use ($paymentMethod, $country): void {
            $paymentQuery->where('status', SubscriptionPayment::STATUS_COMPLETED);
            if ($paymentMethod) {
                $paymentQuery->where('payment_method', $paymentMethod);
            }
            if ($country) {
                $paymentQuery->where('resolved_country', $country);
            }
        });
    }

    private function getEgpAmountSql(): string
    {
        return ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments');
    }

    private function getPaymentDateSql(): string
    {
        return ReportMoneySql::subscriptionPaymentDateSql('subscription_payments');
    }

    /**
     * Explicit grains so API consumers never treat unique people as subscription rows.
     */
    private function metricGrainMeta(): array
    {
        return [
            'total_subscribers' => 'unique_users_with_subscription_starting_in_period',
            'new_unique_subscribers' => 'unique_users_with_subscription_starting_in_period',
            'subscriptions_count' => 'subscription_records_starting_in_period',
            'subscription_records_started' => 'subscription_records_starting_in_period',
            'active_during_period' => 'unique_users_whose_subscription_overlapped_the_period',
            'active_at_period_end' => 'unique_users_active_at_period_end',
            'active_now' => 'unique_users_active_at_current_time',
            'total_active_subscribers' => 'unique_users_active_at_period_end',
            'expired_events' => 'unique_users_with_ends_at_in_period',
            'total_expired_subscribers' => 'unique_users_with_ends_at_in_period',
            'cancelled_events' => 'unique_users_with_cancelled_at_in_period',
            'total_cancelled_subscribers' => 'unique_users_with_cancelled_at_in_period',
            'started_cohort_expired_unique' => 'unique_users_whose_period_start_row_currently_expired',
            'status_distribution' => 'subscription_records_starting_in_period_current_status',
            'total_revenue_egp' => 'completed_subscription_payments_settled_in_period',
            'total_orders' => 'completed_subscription_payments_settled_in_period',
            'catalog_price_egp' => 'current_plan_catalog_price_not_historical_paid_amount',
            'country_price_egp' => 'average_historical_paid_amount_egp_in_country',
            'churned_subscribers' => 'not_defined_immediate_renewal_is_not_churn',
        ];
    }

    private function applyPaymentDateRange($query, Carbon $start, Carbon $end)
    {
        return $query->whereRaw($this->getPaymentDateSql() . ' BETWEEN ? AND ?', [$start, $end]);
    }

    private function buildComparisonMetric(float|int $current, float|int $previous): array
    {
        $diff = $current - $previous;
        $pct = match (true) {
            $previous > 0 => round(($diff / $previous) * 100, 1),
            $current == 0 => 0.0,
            default => null,
        };
        $direction = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'neutral');

        return [
            'current' => $current,
            'previous' => $previous,
            'percentage' => $pct === null ? null : abs($pct),
            'direction' => $direction,
            'is_new' => $previous == 0 && $current > 0,
            'label' => 'عن الفترة السابقة',
        ];
    }

    private function buildRevenueTimeSeries(Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country, string $status, string $preset): array
    {
        $monthly = $preset === '12m' || $start->diffInDays($end) > 90;
        $driver = DB::connection()->getDriverName();
        $paymentDateSql = $this->getPaymentDateSql();
        $bucketSql = match (true) {
            !$monthly => 'DATE(' . $paymentDateSql . ')',
            $driver === 'sqlite' => "strftime('%Y-%m-01', {$paymentDateSql})",
            $driver === 'pgsql' => "TO_CHAR({$paymentDateSql}, 'YYYY-MM-01')",
            default => "DATE_FORMAT({$paymentDateSql}, '%Y-%m-01')",
        };
        $raw = DB::table('subscription_payments')
            ->select(
                DB::raw($bucketSql . ' as date_val'),
                DB::raw('SUM(' . $this->getEgpAmountSql() . ') as total_rev'),
                DB::raw('COUNT(id) as orders_cnt')
            )
            ->where('status', SubscriptionPayment::STATUS_COMPLETED)
            ->whereRaw($paymentDateSql . ' BETWEEN ? AND ?', [$start, $end])
            ->when($paymentMethod, fn($q) => $q->where('payment_method', $paymentMethod))
            ->when($country, fn($q) => $q->where('resolved_country', $country))
            ->when($status !== 'all', fn ($q) => $q->whereExists(function ($sq) use ($status) {
                $sq->selectRaw('1')->from('subscriptions')
                    ->whereColumn('subscriptions.id', 'subscription_payments.subscription_id')
                    ->where('subscriptions.status', $status);
            }))
            ->groupBy(DB::raw($bucketSql))
            ->orderBy('date_val', 'asc')
            ->get();

        $indexed = $raw->keyBy('date_val');
        $series = [];
        $cursor = $monthly ? $start->copy()->startOfMonth() : $start->copy()->startOfDay();
        $last = $monthly ? $end->copy()->startOfMonth() : $end->copy()->startOfDay();
        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m-d');
            $row = $indexed->get($key);
            $series[] = [
                'date' => $key,
                'label' => $monthly ? $cursor->translatedFormat('M Y') : $cursor->translatedFormat('j M'),
                'revenue_egp' => round((float) ($row->total_rev ?? 0), 2),
                'orders_count' => (int) ($row->orders_cnt ?? 0),
            ];
            $cursor = $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return $series;
    }

    private function buildStatusDistribution(Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country, string $status): array
    {
        $q = Subscription::whereBetween('starts_at', [$start, $end]);
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($paymentMethod || $country) {
            $q->whereHas('payments', function ($pq) use ($paymentMethod, $country) {
                $pq->where('status', SubscriptionPayment::STATUS_COMPLETED);
                if ($paymentMethod) $pq->where('payment_method', $paymentMethod);
                if ($country) $pq->where('resolved_country', $country);
            });
        }

        $active = (clone $q)->where('status', Subscription::STATUS_ACTIVE)->count();
        $expired = (clone $q)->where('status', Subscription::STATUS_EXPIRED)->count();
        $pending = (clone $q)->whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])->count();
        $cancelled = (clone $q)->where('status', Subscription::STATUS_CANCELLED)->count();
        $total = $active + $expired + $pending + $cancelled;
        $uniqueActive = (int) (clone $q)->where('status', Subscription::STATUS_ACTIVE)->distinct('user_id')->count('user_id');
        $uniqueExpired = (int) (clone $q)->where('status', Subscription::STATUS_EXPIRED)->distinct('user_id')->count('user_id');
        $uniqueCancelled = (int) (clone $q)->where('status', Subscription::STATUS_CANCELLED)->distinct('user_id')->count('user_id');
        $uniquePending = (int) (clone $q)
            ->whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])
            ->distinct('user_id')
            ->count('user_id');

        return [
            'grain' => 'subscription_records',
            'active_count' => $active,
            'active_percentage' => $total > 0 ? round(($active / $total) * 100, 1) : 0,
            'expired_count' => $expired,
            'expired_percentage' => $total > 0 ? round(($expired / $total) * 100, 1) : 0,
            'pending_count' => $pending,
            'pending_percentage' => $total > 0 ? round(($pending / $total) * 100, 1) : 0,
            'cancelled_count' => $cancelled,
            'cancelled_percentage' => $total > 0 ? round(($cancelled / $total) * 100, 1) : 0,
            'total_count' => $total,
            'unique_active_count' => $uniqueActive,
            'unique_expired_count' => $uniqueExpired,
            'unique_pending_count' => $uniquePending,
            'unique_cancelled_count' => $uniqueCancelled,
        ];
    }

    private function buildPlanSummaries(Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country, string $status): array
    {
        $plans = SubscriptionPlan::withTrashed()->get();
        $result = [];
        $revenueRows = DB::table('subscription_payments')
            ->join('subscriptions', 'subscription_payments.subscription_id', '=', 'subscriptions.id')
            ->select('subscriptions.plan_id')
            ->selectRaw('SUM(' . ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments') . ') as total_revenue')
            ->where('subscription_payments.status', SubscriptionPayment::STATUS_COMPLETED)
            ->whereRaw($this->getPaymentDateSql() . ' BETWEEN ? AND ?', [$start, $end])
            ->when($paymentMethod, fn ($q) => $q->where('subscription_payments.payment_method', $paymentMethod))
            ->when($country, fn ($q) => $q->where('subscription_payments.resolved_country', $country))
            ->when($status !== 'all', fn ($q) => $q->where('subscriptions.status', $status))
            ->groupBy('subscriptions.plan_id')
            ->pluck('total_revenue', 'subscriptions.plan_id');

        foreach ($plans as $plan) {
            $life = $this->buildLifecycleMetrics($start, $end, $status, $paymentMethod, $country, (int) $plan->id);
            $revEgp = (float) ($revenueRows->get($plan->id) ?? 0);
            $variant = $this->resolveBadgeVariant($plan->name);
            $color = $this->resolveBadgeColor($variant);

            $result[] = [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_code' => $plan->slug ?: (string) $plan->id,
                'billing_cycle' => $plan->billing_cycle ?? 'سنوي',
                'badge_variant' => $variant,
                'badge_color' => $color,
                'subscribers_count' => $life['new_unique_subscribers'],
                'total_subscribers' => $life['new_unique_subscribers'],
                'new_unique_subscribers' => $life['new_unique_subscribers'],
                'subscription_records_started' => $life['subscription_records_started'],
                'subscriptions_count' => $life['subscription_records_started'],
                'active_during_period' => $life['active_during_period'],
                'active_at_period_end' => $life['active_at_period_end'],
                'active_now' => $life['active_now'],
                'expired_events' => $life['expired_events'],
                'cancelled_events' => $life['cancelled_events'],
                'active_subscribers' => $life['active_at_period_end'],
                'expired_subscribers' => $life['expired_events'],
                'cancelled_subscribers' => $life['cancelled_events'],
                'started_cohort_active_unique' => $life['started_cohort_active_unique'],
                'started_cohort_expired_unique' => $life['started_cohort_expired_unique'],
                'active_subscription_records' => $life['started_cohort_active_records'],
                'expired_subscription_records' => $life['started_cohort_expired_records'],
                'cancelled_subscription_records' => $life['started_cohort_cancelled_records'],
                'total_revenue_egp' => round($revEgp, 2),
                'price' => (float) $plan->price,
                'catalog_price_egp' => (float) $plan->price,
                'price_kind' => 'current_catalog_price',
                'is_active' => (bool) $plan->is_active,
                'is_deleted' => $plan->deleted_at !== null,
            ];
        }

        return $result;
    }

    private function buildCountryBreakdown(int $planId, Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country, string $status): array
    {
        $egpSql = ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments');
        $paymentDateSql = $this->getPaymentDateSql();

        $countryExpr = ReportMoneySql::unassignedCountrySql(
            'subscription_payments.resolved_country',
            'users.country_code'
        );

        $raw = DB::table('subscriptions')
            ->join('users', 'subscriptions.user_id', '=', 'users.id')
            ->leftJoin('subscription_payments', function ($join) use ($start, $end, $paymentDateSql) {
                $join->on('subscription_payments.subscription_id', '=', 'subscriptions.id')
                    ->where('subscription_payments.status', SubscriptionPayment::STATUS_COMPLETED)
                    ->whereRaw($paymentDateSql . ' BETWEEN ? AND ?', [$start, $end]);
            })
            ->select(
                DB::raw("{$countryExpr} as country_code"),
                DB::raw('COUNT(DISTINCT subscriptions.user_id) as subs_cnt'),
                DB::raw("COUNT(DISTINCT CASE WHEN subscriptions.status = 'active' THEN subscriptions.user_id END) as active_cnt"),
                DB::raw("COUNT(DISTINCT CASE WHEN subscriptions.status = 'expired' THEN subscriptions.user_id END) as expired_cnt"),
                DB::raw("COUNT(DISTINCT CASE WHEN subscriptions.status = 'cancelled' THEN subscriptions.user_id END) as cancelled_cnt"),
                DB::raw('COALESCE(SUM(' . $egpSql . '), 0) as rev_egp'),
                DB::raw('COALESCE(AVG(' . $egpSql . '), 0) as avg_price_egp')
            )
            ->where('subscriptions.plan_id', $planId)
            ->whereBetween('subscriptions.starts_at', [$start, $end])
            ->when($status !== 'all', fn($q) => $q->where('subscriptions.status', $status))
            ->when($paymentMethod, fn($q) => $q->where('subscription_payments.payment_method', $paymentMethod))
            ->when($country, fn($q) => $q->whereRaw("{$countryExpr} = ?", [$country]))
            ->groupBy(DB::raw($countryExpr))
            ->get();

        $table = [];
        $totalSubscribers = 0;
        $totalExpired = 0;
        $totalRevenue = 0.0;

        foreach ($raw as $row) {
            $cc = strtoupper((string) ($row->country_code ?: 'UNASSIGNED'));
            $isUnassigned = in_array($cc, ['UNASSIGNED', 'UNKNOWN'], true);
            $meta = self::COUNTRY_META[$cc] ?? [
                'name_ar' => $isUnassigned ? 'غير محدد' : $cc,
                'name_en' => $isUnassigned ? 'Unassigned' : $cc,
                'flag' => '🌐',
                'color' => '#6b7280',
            ];

            $subs = (int) $row->subs_cnt;
            $exp = (int) $row->expired_cnt;
            $rev = (float) $row->rev_egp;

            $totalSubscribers += $subs;
            $totalExpired += $exp;
            $totalRevenue += $rev;

            $table[] = [
                'country_code' => $cc,
                'country_name_ar' => $meta['name_ar'],
                'country_name_en' => $meta['name_en'],
                'flag_emoji' => $meta['flag'],
                'price_egp' => round((float) $row->avg_price_egp, 2),
                'price_kind' => 'average_historical_paid_amount',
                'subscribers_count' => $subs,
                'active_count' => (int) $row->active_cnt,
                'expired_count' => $exp,
                'cancelled_count' => (int) $row->cancelled_cnt,
                'total_revenue_egp' => round($rev, 2),
                'percentage_share' => 0, // Populated after total
            ];
        }

        // Calculate percentage shares & distribution
        $distribution = [];
        foreach ($table as &$row) {
            $row['percentage_share'] = $totalSubscribers > 0 ? round(($row['subscribers_count'] / $totalSubscribers) * 100, 1) : 0;
            $meta = self::COUNTRY_META[$row['country_code']] ?? ['color' => '#6b7280'];
            $distribution[] = [
                'country_code' => $row['country_code'],
                'country_name' => $row['country_name_ar'],
                'subscribers_count' => $row['subscribers_count'],
                'percentage' => $row['percentage_share'],
                'color' => $meta['color'],
            ];
        }
        unset($row);

        return [
            'table' => $table,
            'totals' => [
                'total_subscribers' => $totalSubscribers,
                'total_expired' => $totalExpired,
                'total_revenue_egp' => round($totalRevenue, 2),
            ],
            'distribution' => $distribution,
        ];
    }

    private function buildMonthlyGrowth(int $planId, ?string $paymentMethod, ?string $country, Carbon $periodStart, Carbon $periodEnd): array
    {
        $series = [];
        $now = Carbon::now();

        for ($i = 11; $i >= 0; $i--) {
            $dt = $now->copy()->subMonths($i);
            $monthStart = $dt->copy()->startOfMonth();
            $monthEnd = $dt->copy()->endOfMonth();

            $base = Subscription::where('plan_id', $planId);
            if ($paymentMethod || $country) {
                $base->whereHas('payments', function ($q) use ($paymentMethod, $country): void {
                    $q->where('status', SubscriptionPayment::STATUS_COMPLETED);
                    if ($paymentMethod) $q->where('payment_method', $paymentMethod);
                    if ($country) $q->where('resolved_country', $country);
                });
            }

            $new = (clone $base)->whereBetween('starts_at', [$monthStart, $monthEnd])->distinct('user_id')->count('user_id');
            $expired = (clone $base)->whereBetween('ends_at', [$monthStart, $monthEnd])->distinct('user_id')->count('user_id');
            $cancelled = (clone $base)->whereBetween('cancelled_at', [$monthStart, $monthEnd])->distinct('user_id')->count('user_id');
            $activeAtEnd = (clone $base)
                ->where('starts_at', '<=', $monthEnd)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $monthEnd))
                ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $monthEnd))
                ->whereNotIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_PENDING_APPROVAL])
                ->distinct('user_id')->count('user_id');

            $series[] = [
                'month' => $dt->format('Y-m'),
                'label_ar' => $dt->translatedFormat('F Y'),
                'label_en' => $dt->format('M Y'),
                'subscribers_count' => $activeAtEnd,
                'new_subscribers' => $new,
                'expired_subscribers' => $expired,
                'cancelled_subscribers' => $cancelled,
                'net_growth' => $new - $expired - $cancelled,
                'active_subscribers_end_of_month' => $activeAtEnd,
                'in_selected_period' => $monthEnd->gte($periodStart) && $monthStart->lte($periodEnd),
                'series_scope' => 'rolling_12_calendar_months',
            ];
        }

        return $series;
    }

    private function resolveBadgeVariant(string $name): string
    {
        $n = strtolower($name);
        if (str_contains($n, 'أساس') || str_contains($n, 'basic')) return 'basic';
        if (str_contains($n, 'ماس') || str_contains($n, 'diamond')) return 'diamond';
        if (str_contains($n, 'فض') || str_contains($n, 'silver')) return 'silver';
        if (str_contains($n, 'ذهب') || str_contains($n, 'gold')) return 'gold';
        if (str_contains($n, 'تجريب') || str_contains($n, 'trial')) return 'trial';
        return 'basic';
    }

    private function resolveBadgeColor(string $variant): string
    {
        return match ($variant) {
            'basic' => '#10b981',
            'diamond' => '#8b5cf6',
            'silver' => '#94a3b8',
            'gold' => '#f59e0b',
            'trial' => '#3b82f6',
            default => '#10b981',
        };
    }
}
