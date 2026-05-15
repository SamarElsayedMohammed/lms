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
        Schema::table('manual_deposit_methods', function (Blueprint $table) {
            $table->json('countries')->nullable()->after('instructions')->comment('JSON array of country codes [EG, SA, etc.]');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_deposit_methods', function (Blueprint $table) {
            $table->dropColumn('countries');
        });
    }
};
