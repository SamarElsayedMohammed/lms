<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Course\Course;
use App\Models\User;

// Disable lazy loading enforcement for the script if it's on
if (method_exists(\Illuminate\Database\Eloquent\Model::class, 'preventLazyLoading')) {
    \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);
}

$courses = Course::with(['user.instructor_details', 'user.roles', 'chapters.lectures'])->get();
echo "Total Courses (non-deleted): " . $courses->count() . "\n";

foreach ($courses as $course) {
    echo "--- Course ID: $course->id | Title: $course->title ---\n";
    $status_ok = ($course->is_active && $course->status == 'publish' && $course->approval_status == 'approved');
    echo "  Status (active/publish/approved): " . ($status_ok ? 'PASS' : 'FAIL') . " ($course->is_active/$course->status/$course->approval_status)\n";
    
    $user = $course->user;
    $user_ok = false;
    if ($user) {
        $user_active = $user->is_active;
        $instructor_approved = $user->instructor_details && $user->instructor_details->status == 'approved';
        $is_admin = $user->roles()->where('name', config('constants.SYSTEM_ROLES.ADMIN'))->exists();
        $user_ok = $user_active && ($instructor_approved || $is_admin);
        echo "  User: ID $user->id | Active: $user_active | Instructor Approved: " . ($instructor_approved ? 'Yes' : 'No') . " | Admin: " . ($is_admin ? 'Yes' : 'No') . " | Global User Check: " . ($user_ok ? 'PASS' : 'FAIL') . "\n";
    } else {
        echo "  User: NOT FOUND\n";
    }
    
    $has_curriculum = false;
    $active_chapters = $course->chapters->filter(fn($c) => $c->is_active);
    foreach ($active_chapters as $chapter) {
        if ($chapter->lectures->where('is_active', true)->isNotEmpty() ||
            $chapter->quizzes()->where('is_active', true)->exists() ||
            $chapter->assignments()->where('is_active', true)->exists() ||
            $chapter->resources()->where('is_active', true)->exists()) {
            $has_curriculum = true;
            break;
        }
    }
    echo "  Active Curriculum Check: " . ($has_curriculum ? 'PASS' : 'FAIL') . " (Active Chapters: " . $active_chapters->count() . ")\n";
    
    if ($status_ok && $user_ok && $has_curriculum) {
        echo "  >>> SHOULD BE RETURNED BY API: YES <<<\n";
    } else {
        echo "  >>> SHOULD BE RETURNED BY API: NO <<<\n";
    }
}
