<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand country_code column from VARCHAR(2) to VARCHAR(10).
     *
     * Some ISO country codes stored in the countries table (e.g. RSD for Serbia)
     * are 3 characters, which caused SQLSTATE[22001] truncation errors.
     * VARCHAR(10) gives safe headroom for any future edge-cases.
     */
    public function up(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            $table->string('country_code', 10)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->change();
        });
    }
};
