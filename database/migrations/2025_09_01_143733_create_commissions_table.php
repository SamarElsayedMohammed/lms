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
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('course_id')->constrained('courses')->onDelete('cascade');
            $table->foreignId('instructor_id')->constrained('users')->onDelete('cascade');
            $table->enum('instructor_type', ['individual', 'team']);
            $table->decimal('course_price', 10, 2); // Original course price
            $table->decimal('discounted_price', 10, 2)->nullable(); // Price after discount (if any)
            $table->decimal('admin_commission_rate', 5, 2); // Commission rate (5.00 or 10.00)
            $table->decimal('admin_commission_amount', 10, 2); // Actual admin commission amount
            $table->decimal('instructor_commission_rate', 5, 2); // Instructor commission rate
            $table->decimal('instructor_commission_amount', 10, 2); // Actual instructor commission amount
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['order_id', 'course_id']);
            $table->index(['instructor_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
