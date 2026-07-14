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
        Schema::table('manual_deposit_methods', function (Blueprint $table) {
            $table->string('currency')->default('EGP')->after('name');
            $table->decimal('min_amount', 12, 2)->default(1)->after('currency');
            $table->decimal('max_amount', 12, 2)->default(1000000)->after('min_amount');
            $table->decimal('fixed_fee', 12, 2)->default(0)->after('max_amount');
            $table->decimal('percent_fee', 5, 2)->default(0)->after('fixed_fee');
            $table->json('dynamic_fields')->nullable()->after('countries');
        });

        Schema::table('manual_deposits', function (Blueprint $table) {
            $table->json('submitted_fields')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_deposit_methods', function (Blueprint $table) {
            $table->dropColumn([
                'currency',
                'min_amount',
                'max_amount',
                'fixed_fee',
                'percent_fee',
                'dynamic_fields'
            ]);
        });

        Schema::table('manual_deposits', function (Blueprint $table) {
            $table->dropColumn('submitted_fields');
        });
    }
};
