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
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropColumn(['minimum_order_amount', 'max_discount_amount']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->integer('minimum_order_amount')->default(0)->after('no_of_users');
            $table->integer('max_discount_amount')->nullable()->after('discount_type');
        });
    }
};
