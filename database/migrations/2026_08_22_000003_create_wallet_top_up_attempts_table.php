<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_top_up_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('order_id')->unique();
            $table->string('provider_transaction_id')->nullable()->index();
            $table->decimal('amount_egp', 12, 2);
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('gateway_response')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at'], 'wallet_topup_user_status_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_top_up_attempts');
    }
};
