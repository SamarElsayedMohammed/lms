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
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // who created the promo

            $table->string('promo_code');
            $table->string('message');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('no_of_users')->nullable(); // null = unlimited
            $table->integer('minimum_order_amount');
            $table->integer('discount');
            $table->enum('discount_type', ['amount', 'percentage']);
            $table->integer('max_discount_amount');
            $table->boolean('repeat_usage')->default(false); // true = allowed
            $table->integer('no_of_repeat_usage')->default(0);
            $table->boolean('status')->default(true); // true = active

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
