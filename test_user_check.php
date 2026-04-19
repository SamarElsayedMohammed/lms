<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Course\Course;

$courses = Course::all();
foreach ($courses as $course) {
    if (!$course->user) {
        echo "ID: $course->id | NO USER\n";
        continue;
    }
    
    $user = $course->user;
    $user_active = $user->is_active;
    
    $instructor_approved = $user->instructor_details()->where('status', 'approved')->exists();
    $is_admin = $user->roles()->where('name', config('constants.SYSTEM_ROLES.ADMIN'))->exists();
    
    $user_check = $user_active && ($instructor_approved || $is_admin);
    
    echo "ID: $course->id | UserID: $user->id | Active: $user_active | InstrAppr: " . ($instructor_approved ? 'Y' : 'N') . " | Admin: " . ($is_admin ? 'Y' : 'N') . " | Check: " . ($user_check ? 'PASS' : 'FAIL') . "\n";
}
