<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix platform_type enum values to lowercase (android, ios)
 * to match the validation rules in ApiController.
 *
 * Before: enum('Android', 'iOS')
 * After:  enum('android', 'ios')
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Normalize existing data to lowercase before changing the enum
        DB::statement("UPDATE user_fcm_tokens SET platform_type = LOWER(platform_type) WHERE platform_type IS NOT NULL");

        // Step 2: Change enum definition to lowercase values
        DB::statement("ALTER TABLE user_fcm_tokens MODIFY COLUMN platform_type ENUM('android', 'ios') NULL DEFAULT NULL");
    }

    public function down(): void
    {
        // Restore to original uppercase values
        DB::statement("UPDATE user_fcm_tokens SET platform_type = CASE
            WHEN LOWER(platform_type) = 'android' THEN 'Android'
            WHEN LOWER(platform_type) = 'ios' THEN 'iOS'
            ELSE platform_type
        END WHERE platform_type IS NOT NULL");

        DB::statement("ALTER TABLE user_fcm_tokens MODIFY COLUMN platform_type ENUM('Android', 'iOS') NULL DEFAULT NULL");
    }
};
