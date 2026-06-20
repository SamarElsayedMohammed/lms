<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            try {
                $table->dropUnique('subscription_plan_prices_plan_id_country_id_unique');
            } catch (\Throwable $e) {
                // ignore
            }
            try {
                $table->dropUnique(['plan_id', 'country_code']);
            } catch (\Throwable $e) {
                // ignore
            }
            
            $table->unique(['plan_id', 'country_id'], 'subscription_plan_prices_plan_id_country_id_unique');
        });
    }

    public function down(): void
    {
        //
    }
};
