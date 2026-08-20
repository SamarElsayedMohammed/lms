<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\Reports\ReportMoneySql;
use Carbon\Carbon;
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
    ];

    /**
     * Get Global Subscription Report summary, metrics, time series, and plan summaries.
     */
    public function getGlobalOverviewReport(array $filters): array
    {
        $dates = $this->resolveFilterDates($filters);
        $currentStart = $dates['current_start'];
        $currentEnd = $dates['current_end'];
        $prevStart = $dates['prev_start'];
        $prevEnd = $dates['prev_end'];

        $paymentMethod = $filters['payment_method'] ?? null;
        $country = isset($filters['country']) ? strtoupper((string) $filters['country']) : null;
        $statusFilter = $filters['status'] ?? 'all';

        // 1. Current Period Metrics
        $currentPaymentsQuery = $this->getBasePaymentsQuery($currentStart, $currentEnd, $paymentMethod, $country, $statusFilter);
        $currentRevenueEgp = (float) (clone $currentPaymentsQuery)->sum(DB::raw(ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments')));
        $currentOrdersCount = (int) (clone $currentPaymentsQuery)->count();

        $currentSubsQuery = $this->getBaseSubscriptionsQuery($currentStart, $currentEnd, $statusFilter, $paymentMethod, $country);
        $currentSubscribersCount = (int) (clone $currentSubsQuery)->distinct('user_id')->count('user_id');
        $currentSubscriptionsCount = (int) (clone $currentSubsQuery)->count();
        $currentActiveCount = (int) (clone $currentSubsQuery)->where('status', Subscription::STATUS_ACTIVE)
            ->distinct('user_id')->count('user_id');
        $currentExpiredCount = (int) (clone $currentSubsQuery)->where('status', Subscription::STATUS_EXPIRED)
            ->distinct('user_id')->count('user_id');
        $currentCancelledCount = (int) (clone $currentSubsQuery)->where('status', Subscription::STATUS_CANCELLED)
            ->distinct('user_id')->count('user_id');

        // 2. Previous Period Metrics (for comparisons)
        $prevPaymentsQuery = $this->getBasePaymentsQuery($prevStart, $prevEnd, $paymentMethod, $country, $statusFilter);
        $prevRevenueEgp = (float) (clone $prevPaymentsQuery)->sum(DB::raw(ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments')));
        $prevOrdersCount = (int) (clone $prevPaymentsQuery)->count();

        $prevSubsQuery = $this->getBaseSubscriptionsQuery($prevStart, $prevEnd, $statusFilter, $paymentMethod, $country);
        $prevSubscribersCount = (int) (clone $prevSubsQuery)->distinct('user_id')->count('user_id');
        $prevExpiredCount = (int) (clone $prevSubsQuery)->where('status', Subscription::STATUS_EXPIRED)
            ->distinct('user_id')->count('user_id');

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
                'total_subscribers' => $currentSubscribersCount,
                'subscriptions_count' => $currentSubscriptionsCount,
                'total_active_subscribers' => $currentActiveCount,
                'total_expired_subscribers' => $currentExpiredCount,
                'total_cancelled_subscribers' => $currentCancelledCount,
                // No suspended state exists in the subscription lifecycle yet.
                'total_suspended_subscribers' => null,
                'comparisons' => $comparisons,
            ],
            'revenue_series' => $revenueSeries,
            'status_distribution' => $statusDistribution,
            'plans' => $plans,
            'meta' => [
                'currency' => 'EGP',
                'timezone' => config('app.timezone'),
                'current_period' => ['from' => $currentStart->toIso8601String(), 'to' => $currentEnd->toIso8601String()],
                'previous_period' => ['from' => $prevStart->toIso8601String(), 'to' => $prevEnd->toIso8601String()],
                'applied_filters' => array_filter($filters, static fn ($value) => $value !== null && $value !== ''),
            ],
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

        $dates = $this->resolveFilterDates($filters);
        $currentStart = $dates['current_start'];
        $currentEnd = $dates['current_end'];
        $prevStart = $dates['prev_start'];
        $prevEnd = $dates['prev_end'];

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

        $totalStudents = (int) (clone $currentPlanSubs)->distinct('user_id')->count('user_id');
        $subscriptionsCount = (int) (clone $currentPlanSubs)->count();
        $activeSubs = (int) (clone $currentPlanSubs)->where('status', Subscription::STATUS_ACTIVE)
            ->distinct('user_id')->count('user_id');
        $expiredSubs = (int) (clone $currentPlanSubs)->where('status', Subscription::STATUS_EXPIRED)
            ->distinct('user_id')->count('user_id');
        $cancelledSubs = (int) (clone $currentPlanSubs)->where('status', Subscription::STATUS_CANCELLED)
            ->distinct('user_id')->count('user_id');
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

        // Monthly growth (12-month series)
        $monthlyGrowth = $this->buildMonthlyGrowth($planId, $paymentMethod, $country);

        return [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan_code' => $plan->slug ?: (string) $plan->id,
            'badge_variant' => $this->resolveBadgeVariant($plan->name),
            'billing_cycle' => $plan->billing_cycle ?? 'سنوي',
            'status' => $plan->is_active ? 'active' : 'inactive',
            'summary' => [
                'total_students' => $totalStudents,
                'total_subscribers' => $totalStudents,
                'subscriptions_count' => $subscriptionsCount,
                'active_subscribers' => $activeSubs,
                'expired_subscribers' => $expiredSubs,
                'cancelled_subscribers' => $cancelledSubs,
                'total_revenue_egp' => round($totalRevenueEgp, 2),
                'comparisons' => $comparisons,
            ],
            'country_breakdown' => $countryBreakdown['table'],
            'country_totals' => $countryBreakdown['totals'],
            'country_distribution' => $countryBreakdown['distribution'],
            'monthly_growth' => $monthlyGrowth,
            'meta' => [
                'currency' => 'EGP',
                'timezone' => config('app.timezone'),
                'current_period' => ['from' => $currentStart->toIso8601String(), 'to' => $currentEnd->toIso8601String()],
                'previous_period' => ['from' => $prevStart->toIso8601String(), 'to' => $prevEnd->toIso8601String()],
                'applied_filters' => array_filter($filters, static fn ($value) => $value !== null && $value !== ''),
            ],
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
        fputcsv($stream, ['الرمز', 'الباقة', 'إجمالي المشتركين', 'النشطين', 'المنتهيين', 'إجمالي الإيرادات (ج.م)']);

        foreach ($plans as $p) {
            $code = $this->escapeCsvFormula((string) ($p['plan_code'] ?? 'plan'));
            $name = $this->escapeCsvFormula((string) $p['plan_name']);
            $total = $p['subscribers_count'] ?? $p['total_subscribers'] ?? 0;
            $active = $p['active_subscribers'];
            $expired = $p['expired_subscribers'] ?? 0;
            $revenue = number_format($p['total_revenue_egp'], 2, '.', '');

            fputcsv($stream, [$code, $name, $total, $active, $expired, $revenue]);
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

    private function resolveFilterDates(array $filters): array
    {
        $preset = $filters['preset'] ?? '30d';
        $now = Carbon::now();

        if ($preset === 'today') {
            $currentStart = $now->copy()->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $now->copy()->subDay()->startOfDay();
            $prevEnd = $now->copy()->subDay()->endOfDay();
        } elseif ($preset === '7d') {
            $currentStart = $now->copy()->subDays(6)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subDays(7);
            $prevEnd = $currentStart->copy()->subSecond();
        } elseif ($preset === '90d') {
            $currentStart = $now->copy()->subDays(89)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subDays(90);
            $prevEnd = $currentStart->copy()->subSecond();
        } elseif ($preset === '12m') {
            $currentStart = $now->copy()->subMonths(11)->startOfMonth();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subMonths(12);
            $prevEnd = $currentStart->copy()->subSecond();
        } elseif ($preset === 'this_month') {
            $currentStart = $now->copy()->startOfMonth();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $prevEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();
        } elseif ($preset === 'last_month') {
            $currentStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $currentEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();
            $prevStart = $now->copy()->subMonthsNoOverflow(2)->startOfMonth();
            $prevEnd = $now->copy()->subMonthsNoOverflow(2)->endOfMonth();
        } elseif ($preset === 'this_year') {
            $currentStart = $now->copy()->startOfYear();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subYearNoOverflow();
            $prevEnd = $currentEnd->copy()->subYearNoOverflow();
        } elseif ($preset === 'all' || $preset === 'all_time') {
            $currentStart = Carbon::create(2000, 1, 1, 0, 0, 0);
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = Carbon::create(2000, 1, 1, 0, 0, 0);
            $prevEnd = Carbon::create(2000, 1, 1, 0, 0, 0);
        } elseif ($preset === 'custom' && !empty($filters['date_from']) && !empty($filters['date_to'])) {
            $currentStart = Carbon::parse($filters['date_from'])->startOfDay();
            $currentEnd = Carbon::parse($filters['date_to'])->endOfDay();
            $days = $currentStart->copy()->startOfDay()->diffInDays($currentEnd->copy()->startOfDay()) + 1;
            $prevStart = $currentStart->copy()->subDays($days)->startOfDay();
            $prevEnd = $currentStart->copy()->subSecond();
        } else {
            // Default 30d
            $currentStart = $now->copy()->subDays(29)->startOfDay();
            $currentEnd = $now->copy()->endOfDay();
            $prevStart = $currentStart->copy()->subDays(30);
            $prevEnd = $currentStart->copy()->subSecond();
        }

        return [
            'current_start' => $currentStart,
            'current_end' => $currentEnd,
            'prev_start' => $prevStart,
            'prev_end' => $prevEnd,
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
        // Older completed rows may predate paid_at. Their creation timestamp is
        // the best available payment event date and prevents historical revenue
        // from silently disappearing from reports.
        return 'COALESCE(subscription_payments.paid_at, subscription_payments.created_at)';
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

        return [
            'active_count' => $active,
            'active_percentage' => $total > 0 ? round(($active / $total) * 100, 1) : 0,
            'expired_count' => $expired,
            'expired_percentage' => $total > 0 ? round(($expired / $total) * 100, 1) : 0,
            'pending_count' => $pending,
            'pending_percentage' => $total > 0 ? round(($pending / $total) * 100, 1) : 0,
            'cancelled_count' => $cancelled,
            'cancelled_percentage' => $total > 0 ? round(($cancelled / $total) * 100, 1) : 0,
            'total_count' => $total,
        ];
    }

    private function buildPlanSummaries(Carbon $start, Carbon $end, ?string $paymentMethod, ?string $country, string $status): array
    {
        $plans = SubscriptionPlan::withTrashed()->get();
        $result = [];

        $subscriberRows = $this->getBaseSubscriptionsQuery($start, $end, $status, $paymentMethod, $country)
            ->selectRaw('plan_id, COUNT(*) as subscriptions_count, COUNT(DISTINCT user_id) as subscribers_count')
            ->groupBy('plan_id')
            ->get()
            ->keyBy('plan_id');
        $activeRows = $this->getBaseSubscriptionsQuery($start, $end, $status, $paymentMethod, $country)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->selectRaw('plan_id, COUNT(DISTINCT user_id) as aggregate_count')
            ->groupBy('plan_id')
            ->pluck('aggregate_count', 'plan_id');
        $expiredRows = $this->getBaseSubscriptionsQuery($start, $end, $status, $paymentMethod, $country)
            ->where('status', Subscription::STATUS_EXPIRED)
            ->selectRaw('plan_id, COUNT(DISTINCT user_id) as aggregate_count')
            ->groupBy('plan_id')
            ->pluck('aggregate_count', 'plan_id');
        $cancelledRows = $this->getBaseSubscriptionsQuery($start, $end, $status, $paymentMethod, $country)
            ->where('status', Subscription::STATUS_CANCELLED)
            ->selectRaw('plan_id, COUNT(DISTINCT user_id) as aggregate_count')
            ->groupBy('plan_id')
            ->pluck('aggregate_count', 'plan_id');
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
            $subscriberRow = $subscriberRows->get($plan->id);
            $subscriptionsCount = (int) ($subscriberRow->subscriptions_count ?? 0);
            $subsCount = (int) ($subscriberRow->subscribers_count ?? 0);
            $activeCount = (int) ($activeRows->get($plan->id) ?? 0);
            $expiredCount = (int) ($expiredRows->get($plan->id) ?? 0);
            $cancelledCount = (int) ($cancelledRows->get($plan->id) ?? 0);
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
                'subscribers_count' => $subsCount,
                'total_subscribers' => $subsCount,
                'subscriptions_count' => $subscriptionsCount,
                'active_subscribers' => $activeCount,
                'expired_subscribers' => $expiredCount,
                'cancelled_subscribers' => $cancelledCount,
                'total_revenue_egp' => round($revEgp, 2),
                'price' => (float) $plan->price,
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

        $raw = DB::table('subscriptions')
            ->join('users', 'subscriptions.user_id', '=', 'users.id')
            ->leftJoin('subscription_payments', function ($join) use ($start, $end, $paymentDateSql) {
                $join->on('subscription_payments.subscription_id', '=', 'subscriptions.id')
                    ->where('subscription_payments.status', SubscriptionPayment::STATUS_COMPLETED)
                    ->whereRaw($paymentDateSql . ' BETWEEN ? AND ?', [$start, $end]);
            })
            ->select(
                DB::raw("UPPER(COALESCE(NULLIF(subscription_payments.resolved_country, ''), NULLIF(users.country_code, ''), 'EG')) as country_code"),
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
            ->when($country, fn($q) => $q->whereRaw("UPPER(COALESCE(NULLIF(subscription_payments.resolved_country, ''), NULLIF(users.country_code, ''), 'EG')) = ?", [$country]))
            ->groupBy(DB::raw("UPPER(COALESCE(NULLIF(subscription_payments.resolved_country, ''), NULLIF(users.country_code, ''), 'EG'))"))
            ->get();

        $table = [];
        $totalSubscribers = 0;
        $totalExpired = 0;
        $totalRevenue = 0.0;

        foreach ($raw as $row) {
            $cc = strtoupper($row->country_code ?: 'UNKNOWN');
            $meta = self::COUNTRY_META[$cc] ?? [
                'name_ar' => $cc === 'UNKNOWN' ? 'غير محدد' : $cc,
                'name_en' => $cc === 'UNKNOWN' ? 'Unknown' : $cc,
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

    private function buildMonthlyGrowth(int $planId, ?string $paymentMethod, ?string $country): array
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
