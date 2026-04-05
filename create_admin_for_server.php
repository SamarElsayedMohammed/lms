<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $user = \App\Models\User::updateOrCreate(
        ['email' => 'superadmin@elms.com'],
        [
            'name' => 'Super Admin',
            'password' => \Illuminate\Support\Facades\Hash::make('Super@Admin#2024!ELMS'),
            'type' => 'email',
            'is_active' => 1,
            'slug' => 'super-admin'
        ]
    );

    if (!$user->hasRole('Admin')) {
        $user->assignRole('Admin');
    }

    echo "Admin Account Created and Role Assigned Successfully!\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
