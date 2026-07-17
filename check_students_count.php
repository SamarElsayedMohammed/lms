<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

$totalUsers = User::count();
$totalStudents = User::role('student')->count();
$activeStudents = User::role('student')->where('is_active', true)->count();
$inactiveStudents = User::role('student')->where('is_active', false)->count();

echo json_encode([
    'total_users' => $totalUsers,
    'total_students' => $totalStudents,
    'active_students' => $activeStudents,
    'inactive_students' => $inactiveStudents,
], JSON_PRETTY_PRINT);
