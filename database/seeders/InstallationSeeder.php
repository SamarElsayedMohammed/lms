<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class InstallationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::updateOrCreate(['name' => 'User']);
        Role::updateOrCreate(['name' => 'Super Admin']);
        Role::updateOrCreate(['name' => 'Seller']);
        Role::updateOrCreate(['name' => 'Staff']);
        Role::updateOrCreate(['name' => 'Author Staff']);

        $user = User::updateOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'admin',
            'email' => 'admin@gmail.com',
            'password' => Hash::make('admin123'),
            'type' => 'email',
        ]);
        $user->syncRoles('Super Admin');
    }
}
