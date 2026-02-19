<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('user_assignment_submissions')) {
            // Modify the enum to include 'suspended'
            DB::statement("ALTER TABLE user_assignment_submissions MODIFY COLUMN status ENUM('pending', 'submitted', 'accepted', 'rejected', 'suspended') DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_assignment_submissions')) {
            // Revert back to enum without suspended
            DB::statement("ALTER TABLE user_assignment_submissions MODIFY COLUMN status ENUM('pending', 'submitted', 'accepted', 'rejected') DEFAULT 'pending'");
        }
    }
};
