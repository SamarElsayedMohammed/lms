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
            // 1. Delete users from oldRole who already have newRole to avoid Duplicate Entry error
            DB::table('model_has_roles')
                ->where('role_id', $oldRole->id)
                ->whereIn('model_id', function ($query) use ($newRole) {
                    $query->select('model_id')
                        ->from('model_has_roles')
                        ->where('role_id', $newRole->id);
                })
                ->delete();

            // 2. Move remaining users from old to new
            DB::table('model_has_roles')
                ->where('role_id', $oldRole->id)
                ->update(['role_id' => $newRole->id]);
            
            // 3. Delete the old role
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
