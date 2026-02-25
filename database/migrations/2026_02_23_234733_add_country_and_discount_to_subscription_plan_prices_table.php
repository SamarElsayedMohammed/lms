<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            // MySQL needs an index for the plan_id foreign key, 
            // so we add a plain index before dropping the unique constraint that contained it.
            $table->index('plan_id');
            $table->dropUnique(['plan_id', 'country_code']);
            // also drop the regular index
            $table->dropIndex(['country_code']);
            $table->dropColumn(['country_code', 'currency_code']);
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->decimal('discount', 10, 2)->nullable();
            
            $table->unique(['plan_id', 'country_id']);
            // Now drop the temporary plan_id index as the new unique constraint covers it
            $table->dropIndex(['plan_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            $table->index('plan_id');
            $table->dropUnique(['plan_id', 'country_id']);
            $table->dropForeign(['country_id']);
            $table->dropColumn(['country_id', 'discount']);
            $table->string('country_code', 2);
            $table->string('currency_code', 3);
            
            $table->index('country_code');
            $table->unique(['plan_id', 'country_code']);
            $table->dropIndex(['plan_id']);
        });
    }
};