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
        // First drop existing foreign keys and unique indexes if they exist
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            try {
                $table->dropUnique(['plan_id', 'country_id']);
            } catch (\Exception $e) {
                // Ignore if not present
            }
            try {
                $table->dropForeign(['country_id']);
            } catch (\Exception $e) {
                // Ignore if not present
            }
        });

        // Drop existing rows since we are replacing country_id with country_code 
        // and we cannot safely map them without knowing all countries.
        DB::table('subscription_plan_prices')->truncate();

        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            $table->dropColumn(['country_id', 'offer_price']);
            
            $table->string('country_code', 2)->after('plan_id')->index();
            $table->string('currency_code', 3)->after('country_code');
            $table->decimal('old_price', 10, 2)->nullable()->after('price');
            $table->boolean('is_active')->default(true)->after('old_price');
            $table->boolean('can_subscribe')->default(true)->after('is_active');
            
            // Add unique index
            $table->unique(['plan_id', 'country_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            $table->dropUnique(['plan_id', 'country_code']);
            $table->dropColumn(['country_code', 'currency_code', 'old_price', 'is_active', 'can_subscribe']);
            $table->foreignId('country_id')->constrained();
            $table->decimal('offer_price', 10, 2)->nullable();
        });
    }
};
