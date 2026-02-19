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
        $transactions = \App\Models\Transaction::whereNull('transaction_id')->get();

        foreach ($transactions as $transaction) {
            $transaction->update([
                'transaction_id' => 'TRX-' . strtoupper(uniqid()),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \App\Models\Transaction::where('transaction_id', 'like', 'TRX-%')->update([
            'transaction_id' => null,
        ]);
    }
};
