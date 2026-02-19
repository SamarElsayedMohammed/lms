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
        Schema::create('wallet_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->enum('type', ['credit', 'debit']);
            $table->enum('transaction_type', ['refund', 'purchase', 'commission', 'withdrawal', 'adjustment']);
            $table->string('reference_id')->nullable(); // For linking to orders, refunds, etc.
            $table->string('reference_type')->nullable(); // Model type (Order, RefundRequest, etc.)
            $table->text('description')->nullable();
            $table->decimal('balance_before', 10, 2);
            $table->decimal('balance_after', 10, 2);
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_histories');
    }
};
