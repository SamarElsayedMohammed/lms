<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('users', function (Blueprint $table) {
                // Check if index already exists
                $indexes = DB::select("SHOW INDEX FROM users WHERE Key_name = 'users_email_unique'");
                if (empty($indexes)) {
                    $table->unique('email', 'users_email_unique');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('users', function (Blueprint $table) {
                $indexes = DB::select("SHOW INDEX FROM users WHERE Key_name = 'users_email_unique'");
                if (!empty($indexes)) {
                    $table->dropUnique('users_email_unique');
                }
            });
        }
    }
};
