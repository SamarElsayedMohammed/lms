<?php

use App\Support\RoleManager;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        RoleManager::ensureCanonicalRolesExist();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep roles intact to prevent data loss
    }
};
