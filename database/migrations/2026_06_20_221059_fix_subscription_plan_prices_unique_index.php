<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // The FK on plan_id needs a backing index. The unique (plan_id, country_code)
        // is currently the only index starting with plan_id, so MySQL refuses to drop it.
        // Fix: add a temporary plain index on plan_id first, so the FK has a fallback.

        // 1. Add a temporary plain index on plan_id so the FK constraint is satisfied
        DB::statement('ALTER TABLE subscription_plan_prices ADD INDEX tmp_plan_id_idx (plan_id)');

        // 2. Now we can safely drop the old unique on (plan_id, country_code)
        $oldIndex = DB::select("
            SELECT COUNT(*) as cnt FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'subscription_plan_prices'
              AND index_name = 'subscription_plan_prices_plan_id_country_code_unique'
        ");
        if (($oldIndex[0]->cnt ?? 0) > 0) {
            DB::statement('ALTER TABLE subscription_plan_prices DROP INDEX subscription_plan_prices_plan_id_country_code_unique');
        }

        // 3. Drop the broken unique on (plan_id, country_id) if it exists
        $newIndex = DB::select("
            SELECT COUNT(*) as cnt FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'subscription_plan_prices'
              AND index_name = 'subscription_plan_prices_plan_id_country_id_unique'
        ");
        if (($newIndex[0]->cnt ?? 0) > 0) {
            DB::statement('ALTER TABLE subscription_plan_prices DROP INDEX subscription_plan_prices_plan_id_country_id_unique');
        }

        // 4. Create the correct composite unique on (plan_id, country_id)
        DB::statement('ALTER TABLE subscription_plan_prices ADD UNIQUE subscription_plan_prices_plan_id_country_id_unique (plan_id, country_id)');

        // 5. Drop the temporary index (the new unique index now covers the FK)
        DB::statement('ALTER TABLE subscription_plan_prices DROP INDEX tmp_plan_id_idx');
    }

    public function down(): void
    {
        //
    }
};
