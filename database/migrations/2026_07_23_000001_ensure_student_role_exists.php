<?php

use App\Models\User;
use App\Support\RoleManager;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        RoleManager::ensureCanonicalRolesExist();

        $studentRole = Role::where('name', RoleManager::ROLE_STUDENT)
            ->where('guard_name', RoleManager::DEFAULT_GUARD)
            ->first();

        if ($studentRole) {
            $unassignedUsers = User::whereDoesntHave('roles')
                ->whereDoesntHave('instructor_details')
                ->get();

            foreach ($unassignedUsers as $user) {
                if (!$user->isAdmin) {
                    $user->assignRole($studentRole);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep roles intact to prevent data loss
    }
};
