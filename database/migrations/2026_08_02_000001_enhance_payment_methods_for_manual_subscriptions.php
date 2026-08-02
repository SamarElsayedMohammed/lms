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
        Schema::table('payment_methods', function (Blueprint $table): void {
            if (!Schema::hasColumn('payment_methods', 'countries')) {
                $table->json('countries')->nullable()->after('instructions');
            }
            if (!Schema::hasColumn('payment_methods', 'currencies')) {
                $table->json('currencies')->nullable()->after('countries');
            }
            if (!Schema::hasColumn('payment_methods', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('account_name');
            }
            if (!Schema::hasColumn('payment_methods', 'iban')) {
                $table->string('iban')->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('payment_methods', 'require_receipt')) {
                $table->boolean('require_receipt')->default(true)->after('is_active');
            }
            if (!Schema::hasColumn('payment_methods', 'min_amount')) {
                $table->decimal('min_amount', 12, 2)->nullable()->after('require_receipt');
            }
            if (!Schema::hasColumn('payment_methods', 'max_amount')) {
                $table->decimal('max_amount', 12, 2)->nullable()->after('min_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $columns = array_filter([
                Schema::hasColumn('payment_methods', 'max_amount') ? 'max_amount' : null,
                Schema::hasColumn('payment_methods', 'min_amount') ? 'min_amount' : null,
                Schema::hasColumn('payment_methods', 'require_receipt') ? 'require_receipt' : null,
                Schema::hasColumn('payment_methods', 'iban') ? 'iban' : null,
                Schema::hasColumn('payment_methods', 'bank_name') ? 'bank_name' : null,
                Schema::hasColumn('payment_methods', 'currencies') ? 'currencies' : null,
                Schema::hasColumn('payment_methods', 'countries') ? 'countries' : null,
            ]);

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
