<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe idempotent migration:
 * Ensures subscription_plan_prices has both country_id (integer FK) and country_code (string).
 *
 * Context:
 *  - Feb 2026: table created with country_id FK
 *  - Jun 10  : migration removed country_id, added country_code
 *  - Jun 17  : attempted to re-add country_id (may or may not have run on production)
 *  - Jun 18  : THIS migration — checks current state and applies only what is missing
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {

            // 1. Add country_id if it is missing
            if (!Schema::hasColumn('subscription_plan_prices', 'country_id')) {
                $table->unsignedBigInteger('country_id')->nullable()->after('plan_id');
            }

            // 2. Add country_code if it is missing
            if (!Schema::hasColumn('subscription_plan_prices', 'country_code')) {
                $table->string('country_code', 2)->nullable()->after('country_id');
            }

            // 3. Add currency_code if it is missing
            if (!Schema::hasColumn('subscription_plan_prices', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('country_code');
            }

            // 4. Add offer_price if it is missing
            if (!Schema::hasColumn('subscription_plan_prices', 'offer_price')) {
                $table->decimal('offer_price', 10, 2)->nullable()->after('price');
            }

            // 5. Add old_price if it is missing
            if (!Schema::hasColumn('subscription_plan_prices', 'old_price')) {
                $table->decimal('old_price', 10, 2)->nullable()->after('price');
            }

            // 6. Add is_active if it is missing
            if (!Schema::hasColumn('subscription_plan_prices', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('offer_price');
            }

            // 7. Add can_subscribe if it is missing
            if (!Schema::hasColumn('subscription_plan_prices', 'can_subscribe')) {
                $table->boolean('can_subscribe')->default(true)->after('is_active');
            }
        });

        // 8. Ensure there is a (plan_id, country_id) unique index if country_id exists
        //    We check via information_schema to avoid exceptions on already-existing indexes.
        if (DB::getDriverName() === 'mysql') {
            $hasUniqueOnCountryId = DB::select("
                SELECT COUNT(*) as cnt
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'subscription_plan_prices'
                  AND index_name = 'subscription_plan_prices_plan_id_country_id_unique'
            ");

            if (($hasUniqueOnCountryId[0]->cnt ?? 0) == 0) {
                Schema::table('subscription_plan_prices', function (Blueprint $table) {
                    // Only add if country_id actually exists now
                    if (Schema::hasColumn('subscription_plan_prices', 'country_id')) {
                        // Drop stale unique on (plan_id, country_code) if it still exists
                        try {
                            $table->dropUnique(['plan_id', 'country_code']);
                        } catch (\Throwable) {
                            // index may already be gone — ignore
                        }

                        $table->unique(['plan_id', 'country_id'], 'subscription_plan_prices_plan_id_country_id_unique');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            try { $table->dropUnique('subscription_plan_prices_plan_id_country_id_unique'); } catch (\Throwable) {}
            foreach (['country_id', 'country_code', 'currency_code', 'offer_price', 'old_price', 'is_active', 'can_subscribe'] as $col) {
                if (Schema::hasColumn('subscription_plan_prices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
