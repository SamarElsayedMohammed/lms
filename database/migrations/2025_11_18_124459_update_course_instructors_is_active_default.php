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
        // Check if doctrine/dbal is available, otherwise use raw SQL
        try {
            Schema::table('course_instructors', function (Blueprint $table) {
                $table->boolean('is_active')->default(1)->change();
            });
        } catch (\Exception $e) {
            // Fallback to raw SQL if doctrine/dbal is not available
            // Works for MySQL/MariaDB
            $driver = DB::getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE course_instructors MODIFY is_active BOOLEAN DEFAULT 1');
            } else {
                // For other databases (PostgreSQL, SQLite, etc.)
                DB::statement('ALTER TABLE course_instructors ALTER COLUMN is_active SET DEFAULT 1');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Check if doctrine/dbal is available, otherwise use raw SQL
        try {
            Schema::table('course_instructors', function (Blueprint $table) {
                $table->boolean('is_active')->default(0)->change();
            });
        } catch (\Exception $e) {
            // Fallback to raw SQL if doctrine/dbal is not available
            // Works for MySQL/MariaDB
            $driver = DB::getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE course_instructors MODIFY is_active BOOLEAN DEFAULT 0');
            } else {
                // For other databases (PostgreSQL, SQLite, etc.)
                DB::statement('ALTER TABLE course_instructors ALTER COLUMN is_active SET DEFAULT 0');
            }
        }
    }
};
