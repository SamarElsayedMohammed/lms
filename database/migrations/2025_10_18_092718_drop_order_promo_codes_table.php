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
        Schema::dropIfExists('order_promo_codes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the order_promo_codes table if needed
        Schema::create('order_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('promo_code_id');
            $table->decimal('discounted_amount', 10, 2)->default(0);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('promo_code_id')->references('id')->on('promo_codes')->onDelete('cascade');
            $table->unique('order_id', 'unique_order_promo_code');
        });
    }
};
