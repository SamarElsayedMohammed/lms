<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            if (!Schema::hasColumn('payment_methods', 'dynamic_fields')) {
                $table->json('dynamic_fields')->nullable()->after('instructions');
            }
            if (!Schema::hasColumn('payment_methods', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('dynamic_fields');
            }
        });

        Schema::table('subscription_payments', function (Blueprint $table): void {
            if (!Schema::hasColumn('subscription_payments', 'method_snapshot')) {
                $table->json('method_snapshot')->nullable()->after('payment_method_id');
            }
            if (!Schema::hasColumn('subscription_payments', 'submitted_fields')) {
                $table->json('submitted_fields')->nullable()->after('method_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('subscription_payments', 'submitted_fields')) {
                $table->dropColumn('submitted_fields');
            }
            if (Schema::hasColumn('subscription_payments', 'method_snapshot')) {
                $table->dropColumn('method_snapshot');
            }
        });

        Schema::table('payment_methods', function (Blueprint $table): void {
            if (Schema::hasColumn('payment_methods', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('payment_methods', 'dynamic_fields')) {
                $table->dropColumn('dynamic_fields');
            }
        });
    }
};
