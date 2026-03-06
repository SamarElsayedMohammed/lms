<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class GrantAllPermissionsToUserOneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Assigns all existing permissions (including countries) to user id 2.
     */
    public function run(): void
    {
        $userId = 2;
        $user = User::find($userId);

        if (!$user) {
            $this->command->warn("User with id {$userId} not found. Skipping.");

            return;
        }

        $permissionNames = Permission::pluck('name')->all();

        if (empty($permissionNames)) {
            $this->command->warn('No permissions found. Run RolePermissionSeeder first.');

            return;
        }

        $user->syncPermissions($permissionNames);

        $this->command->info(sprintf(
            'Granted %d permission(s) to user id %d (%s).',
            count($permissionNames),
            $userId,
            $user->email
        ));
    }
}
