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
        // First, truncate any messages that are longer than 250 characters
        DB::table('legacy_notifications')
            ->whereRaw('LENGTH(message) > 250')
            ->update([
                'message' => DB::raw('LEFT(message, 250)')
            ]);

        // Update message column to VARCHAR(250) for legacy_notifications table
        // Check if doctrine/dbal is available, otherwise use raw SQL
        try {
            Schema::table('legacy_notifications', function (Blueprint $table) {
                $table->string('message', 250)->change();
            });
        } catch (\Exception $e) {
            // Fallback to raw SQL if doctrine/dbal is not available
            $driver = DB::getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE legacy_notifications MODIFY message VARCHAR(250)');
            } else {
                // For other databases (PostgreSQL, SQLite, etc.)
                DB::statement('ALTER TABLE legacy_notifications ALTER COLUMN message TYPE VARCHAR(250)');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert message column back to TEXT (previous migration changed it to text)
        try {
            Schema::table('legacy_notifications', function (Blueprint $table) {
                $table->text('message')->change();
            });
        } catch (\Exception $e) {
            // Fallback to raw SQL if doctrine/dbal is not available
            $driver = DB::getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('ALTER TABLE legacy_notifications MODIFY message TEXT');
            } else {
                // For other databases (PostgreSQL, SQLite, etc.)
                DB::statement('ALTER TABLE legacy_notifications ALTER COLUMN message TYPE TEXT');
            }
        }
    }
};
