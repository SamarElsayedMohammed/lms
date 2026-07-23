<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Support\RoleManager;

$totalUsers = User::count();
$totalStudents = RoleManager::applyRoleFilter(User::query(), 'student')->count();
$activeStudents = RoleManager::applyRoleFilter(User::query(), 'student')->where('is_active', true)->count();
$inactiveStudents = RoleManager::applyRoleFilter(User::query(), 'student')->where('is_active', false)->count();

echo json_encode([
    'total_users' => $totalUsers,
    'total_students' => $totalStudents,
    'active_students' => $activeStudents,
    'inactive_students' => $inactiveStudents,
], JSON_PRETTY_PRINT);
