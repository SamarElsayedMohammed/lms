<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $instructorRole = Role::where('name', 'Instructor')->first();
        if ($instructorRole) {
            $permissions = [
                'courses-restore',
                'courses-trash',
                'courses-requests',
                'courses-rejected'
            ];

            // Ensure permissions exist before giving them
            foreach ($permissions as $permissionName) {
                Permission::findOrCreate($permissionName, 'web');
            }

            $instructorRole->givePermissionTo($permissions);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $instructorRole = Role::where('name', 'Instructor')->first();
        if ($instructorRole) {
            $instructorRole->revokePermissionTo([
                'courses-restore',
                'courses-trash',
                'courses-requests',
                'courses-rejected'
            ]);
        }
    }
};
