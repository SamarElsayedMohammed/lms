<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\SubscriptionPayment;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UnifiedSalesTransactionQuery
{
    /**
     * Server-side UNION of course orders and subscription payments.
     *
     * @return array{paginator: LengthAwarePaginator, pagination_mode: string}
     */
    public function paginate(Request $request, bool $includeSubscriptions): array
    {
        $perPage = max(1, min(100, (int) ($request->per_page ?? 15)));
        $page = max(1, (int) ($request->page ?? 1));
        $productType = strtolower((string) ($request->product_type ?? $request->transaction_type ?? 'all'));

        $union = $this->baseUnion($request, $includeSubscriptions, $productType);
        $count = (int) DB::query()->fromSub((clone $union), 'sales_tx')->count();
        $rows = DB::query()
            ->fromSub($union, 'sales_tx')
            ->orderByDesc('event_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $items = $rows->map(fn ($row) => $this->mapRow($row))->all();

        $paginator = new LengthAwarePaginator($items, $count, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => $request->query(),
        ]);

        return [
            'paginator' => $paginator,
            'pagination_mode' => 'server_side',
        ];
    }

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   exported: int,
     *   total: int,
     *   export_truncated: bool,
     *   export_limit: int,
     *   export_scope: string,
     *   pagination_mode: string
     * }
     */
    public function export(Request $request, bool $includeSubscriptions, int $limit = 5000): array
    {
        $productType = strtolower((string) ($request->product_type ?? $request->transaction_type ?? 'all'));
        $union = $this->baseUnion($request, $includeSubscriptions, $productType);
        $count = (int) DB::query()->fromSub((clone $union), 'sales_tx')->count();
        if ($count > $limit) {
            throw ValidationException::withMessages([
                'export' => "Export is limited to {$limit} rows. Narrow the reporting date window or filters.",
            ]);
        }

        $rows = DB::query()
            ->fromSub($union, 'sales_tx')
            ->orderByDesc('event_at')
            ->limit($limit)
            ->get();

        $items = $rows->map(fn ($row) => $this->mapRow($row))->all();

        return [
            'rows' => $items,
            'data' => $items,
            'exported' => count($items),
            'total' => $count,
            'export_truncated' => $count > $limit,
            'export_limit' => $limit,
            'export_scope' => 'all_filtered_rows',
            'pagination_mode' => 'server_side',
        ];
    }

    /**
     * @param  object  $row
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        $productType = (string) $row->product_type;
        $sourceId = (int) $row->source_id;

        return [
            'id' => $productType === 'subscription' ? 'sub-' . $sourceId : $sourceId,
            'source_id' => $sourceId,
            'product_type' => $productType,
            'order_number' => $row->order_number,
            'status' => $row->status,
            'original_price' => (float) ($row->original_price ?? $row->final_price),
            'discount_amount' => (float) ($row->discount_amount ?? 0),
            'promo_code' => $row->promo_code ?? null,
            'currency_code' => $row->currency_code ?? 'EGP',
            'final_price' => (float) $row->final_price,
            'amount' => (float) $row->amount,
            'payment_method' => $row->payment_method,
            'created_at' => $row->event_at,
            'paid_at' => $row->paid_at,
            'course' => $row->course_title,
            'user' => $row->user_id ? [
                'id' => (int) $row->user_id,
                'name' => $row->user_name,
                'email' => $row->user_email,
            ] : null,
        ];
    }

    private function baseUnion(Request $request, bool $includeSubscriptions, string $productType): Builder
    {
        $orders = $this->orderBranch($request);
        if ($productType === 'subscription') {
            $orders->whereRaw('1 = 0');
        }

        if (!$includeSubscriptions || $productType === 'course' || $productType === 'course_order') {
            return $orders;
        }

        return $orders->unionAll($this->subscriptionBranch($request));
    }

    private function sqlConcat(string $left, string $right): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "{$left} || {$right}";
        }

        return "CONCAT({$left}, {$right})";
    }

    private function orderBranch(Request $request): Builder
    {
        $orderNumberSql = 'COALESCE(orders.order_number, ' . $this->sqlConcat("'ORD-'", 'orders.id') . ')';
        $query = DB::table('orders')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->leftJoin('promo_codes', 'orders.promo_code_id', '=', 'promo_codes.id')
            ->selectRaw("
                orders.id as source_id,
                'course_order' as product_type,
                {$orderNumberSql} as order_number,
                orders.status as status,
                COALESCE(orders.final_price, 0) as final_price,
                " . ReportMoneySql::orderRevenueEgpSql('orders') . " as amount,
                COALESCE(orders.total_price, orders.final_price, 0) as original_price,
                COALESCE(orders.discount_amount, 0) as discount_amount,
                COALESCE(orders.promo_code, promo_codes.promo_code) as promo_code,
                COALESCE(orders.currency_code, 'EGP') as currency_code,
                orders.payment_method as payment_method,
                orders.created_at as event_at,
                orders.created_at as paid_at,
                COALESCE((
                    SELECT c.title FROM order_courses oc
                    INNER JOIN courses c ON c.id = oc.course_id
                    WHERE oc.order_id = orders.id
                    LIMIT 1
                ), 'دورة') as course_title,
                users.id as user_id,
                users.name as user_name,
                users.email as user_email
            ");

        $this->applySharedOrderFilters($query, $request);

        return $query;
    }

    private function subscriptionBranch(Request $request): Builder
    {
        $dateSql = ReportMoneySql::subscriptionPaymentDateSql('subscription_payments');
        $query = DB::table('subscription_payments')
            ->leftJoin('users', 'subscription_payments.user_id', '=', 'users.id')
            ->leftJoin('subscriptions', 'subscription_payments.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->selectRaw("
                subscription_payments.id as source_id,
                'subscription' as product_type,
                " . $this->sqlConcat("'SUB-'", 'subscription_payments.id') . " as order_number,
                subscription_payments.status as status,
                COALESCE(subscription_payments.final_amount, subscription_payments.amount, 0) as final_price,
                " . ReportMoneySql::subscriptionRevenueEgpSql('subscription_payments') . " as amount,
                COALESCE(subscription_payments.original_amount, subscription_payments.amount, 0) as original_price,
                COALESCE(subscription_payments.discount_amount, 0) as discount_amount,
                subscription_payments.promo_code as promo_code,
                COALESCE(subscription_payments.currency_code, 'EGP') as currency_code,
                subscription_payments.payment_method as payment_method,
                {$dateSql} as event_at,
                subscription_payments.paid_at as paid_at,
                COALESCE(subscription_plans.name, 'اشتراك') as course_title,
                users.id as user_id,
                users.name as user_name,
                users.email as user_email
            ");

        $tz = (string) config('app.timezone', 'UTC');
        $dateFrom = $request->date_from ?? $request->from_date;
        $dateTo = $request->date_to ?? $request->to_date;
        if (!empty($dateFrom) && !empty($dateTo)) {
            $query->whereRaw("{$dateSql} BETWEEN ? AND ?", [
                \Carbon\Carbon::parse($dateFrom, $tz)->startOfDay(),
                \Carbon\Carbon::parse($dateTo, $tz)->endOfDay(),
            ]);
        }
        if ($request->filled('payment_method')) {
            $query->where('subscription_payments.payment_method', $request->payment_method);
        }
        if ($request->boolean('card_gateways_only')) {
            $query->whereIn('subscription_payments.payment_method', ['stripe', 'razorpay', 'flutterwave', 'kashier']);
        }
        if ($request->filled('status')) {
            if ($request->status === 'completed') {
                $query->where('subscription_payments.status', SubscriptionPayment::STATUS_COMPLETED);
            } elseif ($request->status === 'pending') {
                $query->where('subscription_payments.status', SubscriptionPayment::STATUS_PENDING);
            } elseif (in_array($request->status, ['cancelled', 'failed'], true)) {
                $query->where('subscription_payments.status', SubscriptionPayment::STATUS_FAILED);
            }
        }

        return $query;
    }

    private function applySharedOrderFilters(Builder $query, Request $request): void
    {
        $tz = (string) config('app.timezone', 'UTC');
        $dateFrom = $request->date_from ?? $request->from_date;
        $dateTo = $request->date_to ?? $request->to_date;
        if (!empty($dateFrom) && !empty($dateTo)) {
            $query->whereBetween('orders.created_at', [
                \Carbon\Carbon::parse($dateFrom, $tz)->startOfDay(),
                \Carbon\Carbon::parse($dateTo, $tz)->endOfDay(),
            ]);
        }
        if ($request->filled('status')) {
            $query->where('orders.status', $request->status);
        }
        if ($request->filled('payment_method')) {
            $query->where('orders.payment_method', $request->payment_method);
        }
        if ($request->boolean('card_gateways_only')) {
            $query->whereIn('orders.payment_method', ['stripe', 'razorpay', 'flutterwave', 'kashier']);
        }
        if ($request->filled('course_id')) {
            $query->whereExists(function ($inner) use ($request): void {
                $inner->selectRaw('1')
                    ->from('order_courses')
                    ->whereColumn('order_courses.order_id', 'orders.id')
                    ->where('order_courses.course_id', $request->course_id);
            });
        }
        if ($request->filled('instructor_id')) {
            $query->whereExists(function ($inner) use ($request): void {
                $inner->selectRaw('1')
                    ->from('order_courses')
                    ->join('courses', 'courses.id', '=', 'order_courses.course_id')
                    ->whereColumn('order_courses.order_id', 'orders.id')
                    ->where('courses.user_id', $request->instructor_id);
            });
        }
        if ($request->filled('category_id')) {
            $query->whereExists(function ($inner) use ($request): void {
                $inner->selectRaw('1')
                    ->from('order_courses')
                    ->join('courses', 'courses.id', '=', 'order_courses.course_id')
                    ->whereColumn('order_courses.order_id', 'orders.id')
                    ->where('courses.category_id', $request->category_id);
            });
        }
    }
}
