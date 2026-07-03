<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_subscription_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->unique(['promo_code_id', 'subscription_plan_id'], 'promo_code_subscription_plan_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_subscription_plan');
    }
};
