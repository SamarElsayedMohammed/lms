<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->decimal('usd_price', 10, 2)->nullable()->after('price');
        });

        // Backfill from existing US country override rows where configured by admin.
        if (Schema::hasTable('subscription_plan_prices') && DB::getDriverName() === 'mysql') {
            DB::statement('
                UPDATE subscription_plans sp
                INNER JOIN subscription_plan_prices spp
                    ON spp.plan_id = sp.id AND spp.country_code = \'US\'
                SET sp.usd_price = spp.price
                WHERE sp.usd_price IS NULL
            ');
        }
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn('usd_price');
        });
    }
};
