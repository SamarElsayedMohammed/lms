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
        // Payment Transactions
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->decimal('amount',10,2);
            $table->decimal('discounted_amount',10,2)->nullable(true);
            $table->enum('payment_gateway',['razorpay'])->nullable(true);
            $table->enum('payment_type',['online'])->comment('Type of payment transaction');
            $table->string('order_id',255)->nullable(true)->comment('Payment Intent Id / Order Id');
            $table->string('transaction_id',255)->nullable(true)->comment('Success Transaction Id');
            $table->enum('payment_status',['success','failed','pending'])->default('pending');
            $table->unique(['payment_gateway','order_id','transaction_id','user_id'],'uniques');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Payment Transactions
        Schema::dropIfExists('payment_transactions');
    }
};
