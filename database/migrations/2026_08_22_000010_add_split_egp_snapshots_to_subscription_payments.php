<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->decimal('wallet_amount_egp', 15, 2)->nullable()->after('amount_egp');
            $table->decimal('gateway_amount_egp', 15, 2)->nullable()->after('wallet_amount_egp');
        });

        DB::table('subscription_payments')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $rate = max(0.0001, (float) ($row->exchange_rate_snapshot ?? 1));
                DB::table('subscription_payments')->where('id', $row->id)->update([
                    'wallet_amount_egp' => round((float) $row->wallet_amount * $rate, 2),
                    'gateway_amount_egp' => round((float) $row->gateway_amount * $rate, 2),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->dropColumn(['wallet_amount_egp', 'gateway_amount_egp']);
        });
    }
};
