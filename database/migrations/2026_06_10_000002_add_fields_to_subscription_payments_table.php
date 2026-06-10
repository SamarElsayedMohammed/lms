<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->string('resolved_country', 2)->nullable()->after('payment_method');
            $table->string('currency_code', 3)->nullable()->after('resolved_country');
            $table->string('price_source')->default('default')->after('currency_code');
            $table->decimal('tax', 10, 2)->default(0)->after('price_source');
            $table->decimal('final_amount', 10, 2)->default(0)->after('tax');
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
