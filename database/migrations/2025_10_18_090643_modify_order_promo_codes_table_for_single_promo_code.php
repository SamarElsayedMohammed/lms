<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if table exists before modifying
        if (Schema::hasTable('order_promo_codes')) {
            // Drop the foreign key constraint first if it exists
            if (Schema::hasColumn('order_promo_codes', 'course_id')) {
                Schema::table('order_promo_codes', function (Blueprint $table) {
                    // Check if foreign key exists before dropping
                    $foreignKeys = DB::select(
                        "SELECT CONSTRAINT_NAME 
                        FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'order_promo_codes' 
                        AND COLUMN_NAME = 'course_id' 
                        AND CONSTRAINT_NAME LIKE '%foreign%'"
                    );
                    
                    if (!empty($foreignKeys)) {
                        $table->dropForeign(['course_id']);
                    }
                });

                // Remove course_id column since we only need one promo code per order
                Schema::table('order_promo_codes', function (Blueprint $table) {
                    $table->dropColumn('course_id');
                });
            }

            // Add unique constraint on order_id to ensure only one promo code per order
            // Check if unique constraint doesn't already exist
            $uniqueExists = DB::select(
                "SELECT CONSTRAINT_NAME 
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'order_promo_codes' 
                AND CONSTRAINT_TYPE = 'UNIQUE' 
                AND CONSTRAINT_NAME = 'unique_order_promo_code'"
            );

            if (empty($uniqueExists)) {
                Schema::table('order_promo_codes', function (Blueprint $table) {
                    $table->unique('order_id', 'unique_order_promo_code');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Check if table exists before modifying
        if (Schema::hasTable('order_promo_codes')) {
            // Drop the unique constraint if it exists
            $uniqueExists = DB::select(
                "SELECT CONSTRAINT_NAME 
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'order_promo_codes' 
                AND CONSTRAINT_TYPE = 'UNIQUE' 
                AND CONSTRAINT_NAME = 'unique_order_promo_code'"
            );

            if (!empty($uniqueExists)) {
                Schema::table('order_promo_codes', function (Blueprint $table) {
                    $table->dropUnique('unique_order_promo_code');
                });
            }

            // Add back the course_id column if it doesn't exist
            if (!Schema::hasColumn('order_promo_codes', 'course_id')) {
                Schema::table('order_promo_codes', function (Blueprint $table) {
                    $table->unsignedBigInteger('course_id')->after('promo_code_id');
                    $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
                });
            }
        }
    }
};
