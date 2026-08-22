<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinar_registrations', function (Blueprint $table): void {
            $table->decimal('amount_egp', 15, 2)->nullable()->after('paid_amount');
            $table->char('currency_code', 3)->nullable()->after('amount_egp');
            $table->decimal('exchange_rate_snapshot', 15, 4)->nullable()->after('currency_code');
            $table->decimal('wallet_amount_egp', 15, 2)->default(0)->after('exchange_rate_snapshot');
            $table->decimal('gateway_amount', 15, 2)->default(0)->after('wallet_amount_egp');
            $table->string('gateway_order_id', 191)->nullable()->unique()->after('gateway_amount');
        });
    }

    public function down(): void
    {
        Schema::table('webinar_registrations', function (Blueprint $table): void {
            $table->dropUnique(['gateway_order_id']);
            $table->dropColumn([
                'amount_egp',
                'currency_code',
                'exchange_rate_snapshot',
                'wallet_amount_egp',
                'gateway_amount',
                'gateway_order_id',
            ]);
        });
    }
};
