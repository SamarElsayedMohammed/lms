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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['online', 'instapay', 'mobile_wallet', 'fawry', 'bank_transfer'])->default('online');
            $table->boolean('is_active')->default(true);
            $table->string('logo')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('instapay_id')->nullable();
            $table->string('merchant_code')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('manual_deposit_method_id')->constrained('payment_methods')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn('payment_method_id');
        });

        Schema::dropIfExists('payment_methods');
    }
};
