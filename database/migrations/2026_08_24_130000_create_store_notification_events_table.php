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
        if (!Schema::hasTable('store_notification_events')) {
            Schema::create('store_notification_events', function (Blueprint $table) {
                $table->id();
                $table->string('store', 32); // 'app_store' | 'google_play'
                $table->string('environment', 32)->default('production'); // 'sandbox' | 'production'
                $table->string('external_event_id', 191); // Apple notificationUUID or Google messageId
                $table->string('event_type', 64); // Apple: DID_RENEW, EXPIRED, etc. Google: SUBSCRIPTION_RENEWED, etc.
                $table->string('event_subtype', 64)->nullable(); // Apple: INITIAL_BUY, GRACE_PERIOD, etc.

                $table->string('store_product_id', 191)->nullable();
                $table->string('transaction_id', 191)->nullable();
                $table->string('original_transaction_id', 191)->nullable();
                $table->string('purchase_token_hash', 64)->nullable();

                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
                $table->foreignId('store_transaction_id')->nullable()->constrained('store_transactions')->nullOnDelete();

                $table->timestamp('event_timestamp')->nullable(); // signedDate / eventTimeMillis
                $table->timestamp('received_at');
                $table->string('processing_status', 32)->default('pending'); // 'pending', 'processing', 'processed', 'ignored', 'failed'
                $table->unsignedInteger('attempt_count')->default(0);
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('processed_at')->nullable();

                $table->string('last_error_code', 64)->nullable();
                $table->text('last_error_message')->nullable();
                $table->json('raw_payload')->nullable();

                $table->timestamps();

                $table->unique(['store', 'external_event_id'], 'store_event_unique');
                $table->index(['store', 'original_transaction_id'], 'store_event_orig_tx_idx');
                $table->index(['store', 'purchase_token_hash'], 'store_event_token_hash_idx');
                $table->index(['processing_status', 'created_at'], 'store_event_status_idx');
                $table->index(['user_id', 'created_at'], 'store_event_user_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_notification_events');
    }
};
