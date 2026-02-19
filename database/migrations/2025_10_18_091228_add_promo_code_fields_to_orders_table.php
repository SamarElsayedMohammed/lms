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
        Schema::table('orders', function (Blueprint $table) {
            // Add promo code related fields
            $table->unsignedBigInteger('promo_code_id')->nullable()->after('payment_method');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('promo_code_id');
            $table->string('promo_code')->nullable()->after('discount_amount');
            
            // Add foreign key constraint
            $table->foreign('promo_code_id')->references('id')->on('promo_codes')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Drop foreign key constraint first
            $table->dropForeign(['promo_code_id']);
            
            // Drop the promo code fields
            $table->dropColumn(['promo_code_id', 'discount_amount', 'promo_code']);
        });
    }
};
