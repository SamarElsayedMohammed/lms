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
        // Rename 'General User' to 'User'
        DB::table('roles')
            ->where('name', 'General User')
            ->where('guard_name', 'web')
            ->update(['name' => 'User']);

        // Rename 'Admin' to 'Super Admin'
        DB::table('roles')
            ->where('name', 'Admin')
            ->where('guard_name', 'web')
            ->update(['name' => 'Super Admin']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert 'User' back to 'General User'
        DB::table('roles')
            ->where('name', 'User')
            ->where('guard_name', 'web')
            ->update(['name' => 'General User']);

        // Revert 'Super Admin' back to 'Admin'
        DB::table('roles')
            ->where('name', 'Super Admin')
            ->where('guard_name', 'web')
            ->update(['name' => 'Admin']);
    }
};
