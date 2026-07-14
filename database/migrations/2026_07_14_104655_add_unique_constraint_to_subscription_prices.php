<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, handle any existing duplicates so the unique constraint doesn't fail
        $duplicates = DB::table('subscription_plan_prices')
            ->select('plan_id', 'country_code', DB::raw('MAX(id) as max_id'))
            ->groupBy('plan_id', 'country_code')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            // Delete all duplicates except the newest one
            DB::table('subscription_plan_prices')
                ->where('plan_id', $duplicate->plan_id)
                ->where('country_code', $duplicate->country_code)
                ->where('id', '!=', $duplicate->max_id)
                ->delete();
        }

        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            $table->unique(['plan_id', 'country_code'], 'sub_plan_prices_plan_country_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            $table->dropUnique('sub_plan_prices_plan_country_code_unique');
        });
    }
};
