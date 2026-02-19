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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->constrained('subscription_plans')->onDelete('cascade');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable()->comment('null for lifetime');
            $table->enum('status', ['active', 'expired', 'cancelled', 'pending'])->default('pending');
            $table->boolean('auto_renew')->default(true);
            $table->boolean('notified_7_days')->default(false)->comment('Notified 7 days before expiry');
            $table->boolean('notified_3_days')->default(false)->comment('Notified 3 days before expiry');
            $table->boolean('notified_1_day')->default(false)->comment('Notified 24 hours before expiry');
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
