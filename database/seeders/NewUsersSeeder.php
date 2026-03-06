<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class NewUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create a new Admin user
        $adminRole = Role::firstOrCreate(
            ['name' => config('constants.SYSTEM_ROLES.ADMIN', 'admin')],
            ['custom_role' => false, 'guard_name' => 'web']
        );
        
        $adminUser = User::updateOrCreate(['email' => 'newadmin@gmail.com'], [
            'name' => 'New Admin',
            'slug' => 'new-admin-user',
            'email' => 'newadmin@gmail.com',
            'password' => Hash::make('password123'),
            'is_active' => 1,
        ]);
        $adminUser->syncRoles($adminRole);

        // Grant all permissions to the new admin
        $permissionNames = Permission::pluck('name')->all();
        if (!empty($permissionNames)) {
            $adminUser->syncPermissions($permissionNames);
        }

        // 2. Create a new regular user
        $regularUser = User::updateOrCreate(['email' => 'newuser@gmail.com'], [
            'name' => 'New User',
            'slug' => 'new-user-account',
            'email' => 'newuser@gmail.com',
            'password' => Hash::make('password123'),
            'is_active' => 1,
        ]);

        $this->command->info('New Admin (newadmin@gmail.com) and New User (newuser@gmail.com) created successfully. Admin was granted all permissions. Password for both is: password123');
    }
}
