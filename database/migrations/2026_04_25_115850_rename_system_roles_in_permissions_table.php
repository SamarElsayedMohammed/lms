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
        $this->mergeRoles('General User', 'User');
        $this->mergeRoles('Admin', 'Super Admin');
    }

    private function mergeRoles(string $oldName, string $newName): void
    {
        $oldRole = DB::table('roles')->where('name', $oldName)->where('guard_name', 'web')->first();
        $newRole = DB::table('roles')->where('name', $newName)->where('guard_name', 'web')->first();

        if ($oldRole && $newRole) {
            // Both exist, move users from old to new and delete old
            DB::table('model_has_roles')
                ->where('role_id', $oldRole->id)
                ->update(['role_id' => $newRole->id]);
            
            DB::table('roles')->where('id', $oldRole->id)->delete();
        } elseif ($oldRole) {
            // Only old exists, rename it
            DB::table('roles')->where('id', $oldRole->id)->update(['name' => $newName]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // For reverse, we just rename back if possible
        DB::table('roles')->where('name', 'User')->update(['name' => 'General User']);
        DB::table('roles')->where('name', 'Super Admin')->update(['name' => 'Admin']);
    }
};
