<?php

declare(strict_types=1);

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
        if (!Schema::hasTable('store_transactions')) {
            Schema::create('store_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
                $table->foreignId('subscription_payment_id')->nullable()->constrained('subscription_payments')->nullOnDelete();
                $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();

                $table->string('store', 32); // 'app_store' | 'google_play'
                $table->string('environment', 32)->default('production'); // 'sandbox' | 'production'
                $table->string('store_product_id', 191);
                $table->string('transaction_id', 191);
                $table->string('original_transaction_id', 191);
                $table->text('purchase_token')->nullable();
                $table->string('purchase_token_hash', 64)->nullable();

                $table->string('status', 32)->default('active'); // 'active', 'expired', 'in_grace_period', 'on_hold', 'paused', 'canceled', 'revoked', 'refunded'
                $table->timestamp('purchased_at');
                $table->timestamp('expires_at')->nullable();
                $table->boolean('auto_renew')->default(true);
                $table->boolean('is_verified')->default(true);
                $table->boolean('is_revoked')->default(false);
                $table->boolean('is_refunded')->default(false);

                $table->decimal('amount', 10, 2)->nullable();
                $table->string('currency', 10)->nullable();
                $table->json('raw_payload')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['store', 'transaction_id'], 'store_tx_unique');
                $table->index(['store', 'original_transaction_id'], 'store_orig_tx_idx');
                $table->index(['user_id', 'store'], 'store_user_idx');
                $table->index(['store', 'purchase_token_hash'], 'store_token_hash_idx');
                $table->index('expires_at');
            });
        }

        // Add optional store linking metadata to subscriptions table
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'store_provider')) {
                $table->string('store_provider', 32)->nullable()->after('activation_mode');
            }
            if (!Schema::hasColumn('subscriptions', 'store_original_transaction_id')) {
                $table->string('store_original_transaction_id', 191)->nullable()->after('store_provider');
                $table->index('store_original_transaction_id', 'sub_store_orig_tx_idx');
            }
        });

        // Add optional store transaction linking to subscription_payments table
        Schema::table('subscription_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_payments', 'store_transaction_id')) {
                $table->foreignId('store_transaction_id')
                    ->nullable()
                    ->after('payment_method_id')
                    ->constrained('store_transactions')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_payments', 'store_transaction_id')) {
                $table->dropForeign(['store_transaction_id']);
                $table->dropColumn('store_transaction_id');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'store_original_transaction_id')) {
                $table->dropIndex('sub_store_orig_tx_idx');
                $table->dropColumn(['store_provider', 'store_original_transaction_id']);
            }
        });

        Schema::dropIfExists('store_transactions');
    }
};
