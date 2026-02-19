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
        Schema::table('wallet_histories', function (Blueprint $table) {
            $table->enum('entry_type', ['user', 'instructor', 'staff'])->default('user')->after('transaction_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_histories', function (Blueprint $table) {
            $table->dropColumn('entry_type');
        });
    }
};
