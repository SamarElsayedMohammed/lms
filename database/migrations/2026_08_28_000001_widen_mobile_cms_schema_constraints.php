<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (Schema::hasTable('feature_sections')) {
                DB::statement('ALTER TABLE feature_sections MODIFY COLUMN type VARCHAR(100) NOT NULL');
            }
            if (Schema::hasTable('sliders')) {
                DB::statement("ALTER TABLE sliders MODIFY COLUMN image VARCHAR(255) NULL DEFAULT ''");
                DB::statement("ALTER TABLE sliders MODIFY COLUMN `order` VARCHAR(50) NOT NULL DEFAULT '0'");
            }
        }
    }

    public function down(): void
    {
        // Keep widened column types for backward compatibility with mobile section types
    }
};
