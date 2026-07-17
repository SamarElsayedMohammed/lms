<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Services\StudentDashboardStatisticsService;

$user = User::whereHas('orderCourses')->orWhereHas('subscriptions')->first();

if (!$user) {
    $user = User::first();
}

if (!$user) {
    echo "No users found in database.\n";
    exit;
}

echo "Testing for User ID: {$user->id} ({$user->email})\n";

try {
    $service = app(StudentDashboardStatisticsService::class);
    $stats = $service->getDashboardStats($user);

    echo "Stats Output:\n";
    print_r($stats);
    echo "\nTEST PASSED: Service executed without errors.\n";
} catch (\Throwable $e) {
    echo "TEST FAILED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
