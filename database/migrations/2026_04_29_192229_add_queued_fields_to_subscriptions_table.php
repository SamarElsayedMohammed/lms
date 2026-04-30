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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('activation_mode', ['active', 'queued'])->default('active');
            $table->timestamp('queued_starts_at')->nullable();
            $table->timestamp('queued_expires_at')->nullable();
            $table->foreignId('parent_subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['parent_subscription_id']);
            $table->dropColumn([
                'activation_mode',
                'queued_starts_at',
                'queued_expires_at',
                'parent_subscription_id',
                'paid_at',
            ]);
        });
    }
};
