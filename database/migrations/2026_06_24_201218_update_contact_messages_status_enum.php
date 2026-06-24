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
        // Safe way to update an ENUM column without Doctrine DBAL issues
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE `contact_messages` MODIFY COLUMN `status` ENUM('new', 'waiting_admin', 'replied', 'closed', 'completed', 'reopened') DEFAULT 'new'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // To be safe when rolling back, any new statuses should ideally be mapped back to 'closed' or 'new'
        // before running this, but for the scope of this migration we revert the schema.
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE `contact_messages` MODIFY COLUMN `status` ENUM('new', 'read', 'replied', 'closed') DEFAULT 'new'");
    }
};
