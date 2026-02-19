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
        Schema::create('affiliate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->unsignedInteger('total_clicks')->default(0);
            $table->unsignedInteger('total_conversions')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->enum('status', ['pending', 'available', 'withdrawn', 'cancelled'])->default('pending');
            $table->date('earned_date');
            $table->date('available_date');
            $table->date('settlement_period_start');
            $table->date('settlement_period_end');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
            $table->index('available_date');
        });

        Schema::create('affiliate_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->json('commission_ids');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'rejected'])->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('affiliate_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_withdrawals');
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_links');
    }
};
