<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $tables = [
            'wallet_histories' => ['amount_egp', 'exchange_rate_snapshot', 'currency_code'],
            'manual_deposits' => ['amount_egp', 'exchange_rate_snapshot', 'currency_code'],
            'withdrawal_requests' => ['amount_egp', 'exchange_rate_snapshot', 'currency_code']
        ];

        foreach ($tables as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                if (in_array('amount_egp', $columns) && !Schema::hasColumn($t->getTable(), 'amount_egp')) {
                    $t->decimal('amount_egp', 10, 2)->nullable();
                }
                if (in_array('exchange_rate_snapshot', $columns) && !Schema::hasColumn($t->getTable(), 'exchange_rate_snapshot')) {
                    $t->decimal('exchange_rate_snapshot', 10, 4)->nullable();
                }
                if (in_array('currency_code', $columns) && !Schema::hasColumn($t->getTable(), 'currency_code')) {
                    $t->string('currency_code', 3)->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $tables = ['wallet_histories', 'manual_deposits', 'withdrawal_requests'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $columnsToDrop = [];
                if (Schema::hasColumn($t->getTable(), 'amount_egp')) {
                    $columnsToDrop[] = 'amount_egp';
                }
                if (Schema::hasColumn($t->getTable(), 'exchange_rate_snapshot')) {
                    $columnsToDrop[] = 'exchange_rate_snapshot';
                }
                if (Schema::hasColumn($t->getTable(), 'currency_code')) {
                    $columnsToDrop[] = 'currency_code';
                }
                if (!empty($columnsToDrop)) {
                    $t->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
