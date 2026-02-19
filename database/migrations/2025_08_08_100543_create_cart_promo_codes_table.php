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
        Schema::create('cart_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');          // Who applied
            $table->unsignedBigInteger('promo_code_id');    // Which promo code
            $table->unsignedBigInteger('course_id');        // Course it applies to
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('promo_code_id')->references('id')->on('promo_codes')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');

            //$table->unique(['user_id', 'course_id'], 'unique_user_course_promo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_promo_codes');
    }
};
