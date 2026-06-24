<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-add `read` to contact_messages.status — admin viewed but not yet replied.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE `contact_messages` MODIFY COLUMN `status` ENUM('new', 'read', 'waiting_admin', 'replied', 'closed', 'completed', 'reopened') DEFAULT 'new'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE `contact_messages` SET `status` = 'new' WHERE `status` = 'read'");

        DB::statement("ALTER TABLE `contact_messages` MODIFY COLUMN `status` ENUM('new', 'waiting_admin', 'replied', 'closed', 'completed', 'reopened') DEFAULT 'new'");
    }
};
