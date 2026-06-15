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
        Schema::table('subscription_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_payments', 'resolved_country')) {
                $table->string('resolved_country', 2)->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('subscription_payments', 'currency_code')) {
                $table->string('currency_code', 3)->nullable()->after('resolved_country');
            }
            if (!Schema::hasColumn('subscription_payments', 'price_source')) {
                $table->string('price_source')->default('default')->after('currency_code');
            }
            if (!Schema::hasColumn('subscription_payments', 'tax')) {
                $table->decimal('tax', 10, 2)->default(0)->after('price_source');
            }
            if (!Schema::hasColumn('subscription_payments', 'final_amount')) {
                $table->decimal('final_amount', 10, 2)->default(0)->after('tax');
            }
        });
        
        // Update final_amount for existing records to match amount
        DB::statement('UPDATE subscription_payments SET final_amount = amount');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn(['resolved_country', 'currency_code', 'price_source', 'tax', 'final_amount']);
        });
    }
};
