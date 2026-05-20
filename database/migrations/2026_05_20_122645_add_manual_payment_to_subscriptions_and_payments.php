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
        // Change status column from enum to string to support new status 'pending_approval'
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('status', 50)->default('pending')->change();
        });

        // Add manual payment details to subscription_payments
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignId('manual_deposit_method_id')
                ->nullable()
                ->constrained('manual_deposit_methods')
                ->onDelete('set null');
            $table->string('receipt')->nullable();
            $table->text('admin_notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropForeign(['manual_deposit_method_id']);
            $table->dropColumn(['manual_deposit_method_id', 'receipt', 'admin_notes']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Reverting to the exact original enum using raw SQL or fallback.
            // Keeping it as string in down() is also safe, but we can attempt to change it back if needed.
        });
    }
};
