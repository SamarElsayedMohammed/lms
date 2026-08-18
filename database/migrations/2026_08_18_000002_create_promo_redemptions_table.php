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
        if (!Schema::hasTable('promo_redemptions')) {
            Schema::create('promo_redemptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
                $table->string('promo_code')->index();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
                $table->foreignId('subscription_payment_id')->nullable()->constrained('subscription_payments')->nullOnDelete();
                
                $table->string('status', 32)->default('reserved')->index(); // reserved, consumed, released, expired
                $table->string('currency', 10)->default('EGP');
                $table->decimal('original_amount', 10, 2)->default(0);
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->decimal('final_amount', 10, 2)->default(0);
                $table->string('discount_type_snapshot', 32)->nullable();
                $table->decimal('discount_value_snapshot', 10, 2)->nullable();
                
                $table->timestamp('reserved_at')->nullable()->index();
                $table->timestamp('consumed_at')->nullable()->index();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();

                $table->index(['promo_code', 'status']);
                $table->index(['user_id', 'promo_code', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_redemptions');
    }
};
