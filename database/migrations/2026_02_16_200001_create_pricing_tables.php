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
        Schema::create('supported_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->unique();
            $table->string('country_name');
            $table->string('currency_code', 3);
            $table->string('currency_symbol', 10);
            $table->decimal('exchange_rate_to_egp', 10, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('subscription_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->string('currency_code', 3);
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->unique(['plan_id', 'country_code']);
            $table->index('country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_prices');
        Schema::dropIfExists('supported_currencies');
    }
};
