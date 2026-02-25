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
     * Assigns all existing permissions to user id 1.
     */
    public function run(): void
    {
        $user = User::find(1);

        if (!$user) {
            $this->command->warn('User with id 1 not found. Skipping.');

            return;
        }

        $permissionNames = Permission::pluck('name')->all();

        if (empty($permissionNames)) {
            $this->command->warn('No permissions found. Run RolePermissionSeeder first.');

            return;
        }

        $user->syncPermissions($permissionNames);

        $this->command->info(sprintf(
            'Granted %d permission(s) to user id 1 (%s).',
            count($permissionNames),
            $user->email
        ));
    }
}
