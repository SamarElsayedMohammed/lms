<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $role = Role::updateOrCreate(['name' => config('constants.SYSTEM_ROLES.ADMIN')], ['custom_role' => false]);
        $user = User::updateOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'admin',
            'slug' => 'admin-slug',
            'email' => 'admin@gmail.com',
            'password' => Hash::make('admin123'),
            'is_active' => 1,
        ]);
        $user->syncRoles($role);
    }
}
