<?php

namespace App\Services\Reports;

final class ReportMoneySql
{
    public static function orderRevenueEgpSql(string $alias = 'orders'): string
    {
        return "COALESCE(NULLIF({$alias}.amount_egp, 0), {$alias}.final_price * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), {$alias}.final_price, 0)";
    }

    public static function orderRevenueLocalSql(string $alias = 'orders'): string
    {
        return "COALESCE({$alias}.final_price, 0)";
    }

    public static function subscriptionRevenueEgpSql(string $alias = 'subscription_payments'): string
    {
        return "COALESCE(NULLIF({$alias}.amount_egp, 0), NULLIF({$alias}.final_amount, 0) * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), NULLIF({$alias}.amount, 0) * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), 0)";
    }

    public static function subscriptionRevenueLocalSql(string $alias = 'subscription_payments'): string
    {
        return "COALESCE(NULLIF({$alias}.final_amount, 0), {$alias}.amount, 0)";
    }

    public static function refundAmountEgpSql(string $alias = 'refund_requests'): string
    {
        return "COALESCE(NULLIF({$alias}.amount_egp, 0), {$alias}.refund_amount * COALESCE(NULLIF({$alias}.exchange_rate_snapshot, 0), 1), {$alias}.refund_amount, 0)";
    }
}
