<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'apple' to the social_logins.type enum.
 *
 * Before: enum('google', 'email', 'phone')
 * After:  enum('google', 'email', 'phone', 'apple')
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE social_logins MODIFY COLUMN type ENUM('google', 'email', 'phone', 'apple') NOT NULL");
    }

    public function down(): void
    {
        // Remove apple entries first to avoid constraint violation on rollback
        DB::statement("DELETE FROM social_logins WHERE type = 'apple'");

        DB::statement("ALTER TABLE social_logins MODIFY COLUMN type ENUM('google', 'email', 'phone') NOT NULL");
    }
};
