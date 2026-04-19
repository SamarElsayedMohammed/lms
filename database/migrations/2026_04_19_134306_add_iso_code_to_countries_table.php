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
        Schema::table('countries', function (Blueprint $table) {
            $table->string('iso_code', 2)->nullable()->after('id')->unique();
            $table->string('currency_code', 3)->nullable()->after('currency_name');
        });

        // Try to populate Egypt if it exists as the placeholder
        \Illuminate\Support\Facades\DB::table('countries')
            ->where('id', 1)
            ->update([
                'name_en' => 'Egypt',
                'name_ar' => 'مصر',
                'iso_code' => 'EG',
                'currency_name' => 'Egyptian Pound',
                'currency_code' => 'EGP',
            ]);
            
        // Also populate Saudi Arabia if it exists
         \Illuminate\Support\Facades\DB::table('countries')
            ->where('name_en', 'Saudi Arabia')
            ->update([
                'iso_code' => 'SA',
                'currency_code' => 'SAR',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['iso_code', 'currency_code']);
        });
    }
};
