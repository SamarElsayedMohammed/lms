<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the persisted archive state used by the existing course editor.
     *
     * The conversion is written explicitly for MySQL-compatible production
     * databases. SQLite test databases do not enforce enum values, so the
     * column remains usable there without a destructive table rebuild.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('courses', 'status')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE courses MODIFY status ENUM('draft', 'pending', 'publish', 'archive') NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('courses', 'status') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('courses')->where('status', 'archive')->update([
            'status' => 'draft',
            'is_active' => 0,
        ]);

        DB::statement("ALTER TABLE courses MODIFY status ENUM('draft', 'pending', 'publish') NOT NULL DEFAULT 'draft'");
    }
};
