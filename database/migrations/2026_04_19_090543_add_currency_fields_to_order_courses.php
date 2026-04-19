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
        Schema::table('order_courses', function (Blueprint $table) {
            $table->decimal('amount_egp', 10, 2)->nullable()->after('price');
            $table->char('currency_code', 3)->nullable()->after('amount_egp');
            $table->decimal('exchange_rate_snapshot', 10, 4)->nullable()->after('currency_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_courses', function (Blueprint $table) {
            $table->dropColumn(['amount_egp', 'currency_code', 'exchange_rate_snapshot']);
        });
    }
};
