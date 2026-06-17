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
        Schema::table('supported_currencies', function (Blueprint $table) {
            // السعر اليدوي المخصص (اختياري)
            $table->decimal('manual_exchange_rate_to_egp', 15, 6)->nullable()->after('exchange_rate_to_egp')
                ->comment('السعر اليدوي المخصص للعملة');
            // زر التوجل: هل نستخدم السعر اليدوي أم البنكي؟
            $table->boolean('use_manual_rate')->default(false)->after('manual_exchange_rate_to_egp')
                ->comment('هل نستخدم السعر اليدوي بدلاً من سعر البنك؟');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supported_currencies', function (Blueprint $table) {
            $table->dropColumn(['manual_exchange_rate_to_egp', 'use_manual_rate']);
        });
    }
};
