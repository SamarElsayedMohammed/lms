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
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('country_id')->nullable()->after('plan_id');
            $table->decimal('offer_price', 10, 2)->nullable()->after('price');
            
            // Re-add foreign key if needed, or leave it as simple column depending on your setup
            // $table->foreign('country_id')->references('id')->on('countries')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plan_prices', function (Blueprint $table) {
            $table->dropColumn(['country_id', 'offer_price']);
        });
    }
};
