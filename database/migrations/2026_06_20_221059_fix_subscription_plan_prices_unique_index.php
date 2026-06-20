<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Disable FK checks so we can freely drop indexes
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // 1. Drop the old (plan_id, country_code) unique if it exists
            $oldIndex = DB::select("
                SELECT COUNT(*) as cnt FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'subscription_plan_prices'
                  AND index_name = 'subscription_plan_prices_plan_id_country_code_unique'
            ");
            if (($oldIndex[0]->cnt ?? 0) > 0) {
                DB::statement('ALTER TABLE subscription_plan_prices DROP INDEX subscription_plan_prices_plan_id_country_code_unique');
            }

            // 2. Drop the broken (plan_id, country_id) unique if it exists
            $newIndex = DB::select("
                SELECT COUNT(*) as cnt FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'subscription_plan_prices'
                  AND index_name = 'subscription_plan_prices_plan_id_country_id_unique'
            ");
            if (($newIndex[0]->cnt ?? 0) > 0) {
                DB::statement('ALTER TABLE subscription_plan_prices DROP INDEX subscription_plan_prices_plan_id_country_id_unique');
            }

            // 3. Recreate the composite unique properly on (plan_id, country_id)
            DB::statement('ALTER TABLE subscription_plan_prices ADD UNIQUE subscription_plan_prices_plan_id_country_id_unique (plan_id, country_id)');

        } finally {
            // Always re-enable FK checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        //
    }
};
