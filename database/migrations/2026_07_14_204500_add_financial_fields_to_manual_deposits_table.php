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
        Schema::table('manual_deposits', function (Blueprint $table) {
            $table->decimal('fee_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('net_amount', 15, 2)->default(0)->after('fee_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_deposits', function (Blueprint $table) {
            $table->dropColumn(['fee_amount', 'net_amount']);
        });
    }
};
