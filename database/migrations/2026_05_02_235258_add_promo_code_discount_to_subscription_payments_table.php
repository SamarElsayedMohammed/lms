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
            $table->string('promo_code')->nullable()->after('payment_method')->comment('Promo code used');
            $table->decimal('original_amount', 10, 2)->nullable()->after('promo_code')->comment('Original price before discount');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('original_amount')->comment('Discount amount applied');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn(['promo_code', 'original_amount', 'discount_amount']);
        });
    }
};
