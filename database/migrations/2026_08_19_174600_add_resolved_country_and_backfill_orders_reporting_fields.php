<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'resolved_country')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->char('resolved_country', 2)->nullable()->after('currency_code');
                $table->index('resolved_country');
            });
        }

        // Backfill country from user profile for historical rows.
        DB::table('orders')
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                $userIds = $orders
                    ->pluck('user_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($userIds === []) return;

                $countries = DB::table('users')
                    ->whereIn('id', $userIds)
                    ->pluck('country_code', 'id');

                foreach ($orders as $order) {
                    $country = $countries[$order->user_id] ?? null;
                    if (!is_string($country) || $country === '') continue;
                    DB::table('orders')
                        ->where('id', $order->id)
                        ->whereNull('resolved_country')
                        ->update(['resolved_country' => strtoupper(substr($country, 0, 2))]);
                }
            });

        // Backfill amount_egp/currency/rate from order_courses aggregates.
        DB::table('orders')
            ->select(['id', 'tax_price', 'amount_egp', 'currency_code', 'exchange_rate_snapshot'])
            ->orderBy('id')
            ->chunkById(400, function ($orders): void {
                $orderIds = $orders->pluck('id')->all();
                if ($orderIds === []) return;

                $lineAgg = DB::table('order_courses')
                    ->whereIn('order_id', $orderIds)
                    ->selectRaw('order_id, SUM(COALESCE(amount_egp, 0)) as lines_egp')
                    ->selectRaw('MAX(currency_code) as line_currency')
                    ->selectRaw('AVG(NULLIF(exchange_rate_snapshot, 0)) as avg_rate')
                    ->groupBy('order_id')
                    ->get()
                    ->keyBy('order_id');

                foreach ($orders as $order) {
                    $agg = $lineAgg->get($order->id);
                    if ($agg === null) continue;

                    $derivedRate = (float) ($agg->avg_rate ?? 0);
                    if ($derivedRate <= 0) $derivedRate = (float) ($order->exchange_rate_snapshot ?? 0);
                    if ($derivedRate <= 0) $derivedRate = 1.0;

                    $linesEgp = (float) ($agg->lines_egp ?? 0);
                    $taxEgp = (float) ($order->tax_price ?? 0) * $derivedRate;
                    $computedAmountEgp = round($linesEgp + $taxEgp, 2);

                    $update = [];
                    if (((float) ($order->amount_egp ?? 0)) <= 0 && $computedAmountEgp > 0) {
                        $update['amount_egp'] = $computedAmountEgp;
                    }
                    if ((!is_string($order->currency_code) || $order->currency_code === '') && is_string($agg->line_currency) && $agg->line_currency !== '') {
                        $update['currency_code'] = strtoupper($agg->line_currency);
                    }
                    if (((float) ($order->exchange_rate_snapshot ?? 0)) <= 0 && $derivedRate > 0) {
                        $update['exchange_rate_snapshot'] = $derivedRate;
                    }

                    if ($update !== []) {
                        DB::table('orders')->where('id', $order->id)->update($update);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'resolved_country')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropIndex(['resolved_country']);
                $table->dropColumn('resolved_country');
            });
        }
    }
};
