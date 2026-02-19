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
        Schema::table('order_courses', function (Blueprint $table) {
            $table->boolean('certificate_purchased')->default(false)->after('tax_price');
            $table->decimal('certificate_fee', 10, 2)->nullable()->after('certificate_purchased');
            $table->timestamp('certificate_purchased_at')->nullable()->after('certificate_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_courses', function (Blueprint $table) {
            $table->dropColumn(['certificate_purchased', 'certificate_fee', 'certificate_purchased_at']);
        });
    }
};
