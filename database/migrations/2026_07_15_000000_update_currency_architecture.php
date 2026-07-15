<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 1. Add fields to tables
        
        $tables = [
            'orders' => ['amount_egp', 'exchange_rate_snapshot', 'currency_code'],
            'transactions' => ['amount_egp', 'exchange_rate_snapshot', 'currency_code'],
            'subscription_payments' => ['amount_egp', 'exchange_rate_snapshot'],
            'refund_requests' => ['amount_egp', 'exchange_rate_snapshot', 'currency_code']
        ];

        foreach ($tables as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                if (in_array('amount_egp', $columns) && !Schema::hasColumn($t->getTable(), 'amount_egp')) {
                    $t->decimal('amount_egp', 10, 2)->nullable();
                }
                if (in_array('exchange_rate_snapshot', $columns) && !Schema::hasColumn($t->getTable(), 'exchange_rate_snapshot')) {
                    $t->decimal('exchange_rate_snapshot', 10, 4)->nullable();
                }
                if (in_array('currency_code', $columns) && !Schema::hasColumn($t->getTable(), 'currency_code')) {
                    $t->string('currency_code', 3)->nullable();
                }
            });
        }

        // 2. Restore pricing columns to courses if they were dropped
        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'price')) {
                $table->decimal('price', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('courses', 'discount_price')) {
                $table->decimal('discount_price', 10, 2)->nullable();
            }
        });

        // 3. Auto-convert existing courses EGP base prices to USD
        // Fetch current active USD exchange rate
        $usdCurrency = DB::table('supported_currencies')->where('currency_code', 'USD')->where('is_active', true)->first();
        $usdRate = $usdCurrency ? (float) ($usdCurrency->active_exchange_rate ?? 1.0) : 1.0;
        
        if ($usdRate <= 0) {
            $usdRate = 1.0;
        }

        // Convert course prices
        $courses = DB::table('courses')->whereNotNull('price')->get();
        foreach ($courses as $course) {
            DB::table('courses')->where('id', $course->id)->update([
                'price' => round($course->price / $usdRate, 2),
                'discount_price' => $course->discount_price !== null ? round($course->discount_price / $usdRate, 2) : null,
            ]);
        }

        // Convert subscription plans prices
        $plans = DB::table('subscription_plans')->whereNotNull('price')->get();
        foreach ($plans as $plan) {
            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'price' => round($plan->price / $usdRate, 2),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Revert EGP prices
        $usdCurrency = DB::table('supported_currencies')->where('currency_code', 'USD')->where('is_active', true)->first();
        $usdRate = $usdCurrency ? (float) ($usdCurrency->active_exchange_rate ?? 1.0) : 1.0;

        $courses = DB::table('courses')->whereNotNull('price')->get();
        foreach ($courses as $course) {
            DB::table('courses')->where('id', $course->id)->update([
                'price' => round($course->price * $usdRate, 2),
                'discount_price' => $course->discount_price !== null ? round($course->discount_price * $usdRate, 2) : null,
            ]);
        }

        $plans = DB::table('subscription_plans')->whereNotNull('price')->get();
        foreach ($plans as $plan) {
            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'price' => round($plan->price * $usdRate, 2),
            ]);
        }
        
        // Note: we avoid dropping columns in down() to prevent data loss in a rollback
    }
};
