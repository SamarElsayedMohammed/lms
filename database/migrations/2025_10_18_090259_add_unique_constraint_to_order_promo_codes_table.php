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
        Schema::table('order_promo_codes', function (Blueprint $table) {
            // Add unique constraint to ensure only one promo code per order
            $table->unique('order_id', 'unique_order_promo_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_promo_codes', function (Blueprint $table) {
            // Drop the unique constraint
            $table->dropUnique('unique_order_promo_code');
        });
    }
};
