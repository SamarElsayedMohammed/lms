<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->decimal('fee_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('net_amount', 15, 2)->nullable()->after('fee_amount');
            $table->decimal('fee_amount_egp', 15, 2)->default(0)->after('amount_egp');
            $table->decimal('net_amount_egp', 15, 2)->nullable()->after('fee_amount_egp');
            $table->json('method_snapshot')->nullable()->after('payment_method');
        });

        // Existing records had no applied fee, so their net value equals the request amount.
        \Illuminate\Support\Facades\DB::table('withdrawal_requests')->update([
            'net_amount' => \Illuminate\Support\Facades\DB::raw('amount'),
            'net_amount_egp' => \Illuminate\Support\Facades\DB::raw('COALESCE(amount_egp, amount)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'fee_amount',
                'net_amount',
                'fee_amount_egp',
                'net_amount_egp',
                'method_snapshot',
            ]);
        });
    }
};
