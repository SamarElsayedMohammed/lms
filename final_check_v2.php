<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Course\Course;

$all_courses = Course::withTrashed()->with(['user.roles', 'user.instructor_details'])->get();
echo "Total (including deleted): " . $all_courses->count() . "\n";

foreach ($all_courses as $c) {
    $reasons = [];
    if ($c->deleted_at) $reasons[] = "Deleted";
    if (!$c->is_active) $reasons[] = "Not Active";
    if ($c->status !== 'publish') $reasons[] = "Status: " . $c->status;
    if ($c->approval_status !== 'approved') $reasons[] = "Approval: " . $c->approval_status;
    
    $user = $c->user;
    if (!$user) {
        $reasons[] = "No User";
    } else {
        if (!$user->is_active) $reasons[] = "User Inactive";
        $instructor_approved = $user->instructor_details && $user->instructor_details->status == 'approved';
        $is_admin = $user->roles->where('name', 'Admin')->isNotEmpty();
        if (!$instructor_approved && !$is_admin) $reasons[] = "User not approved/admin";
    }
    
    $has_chapters = $c->chapters()->where('is_active', 1)->exists();
    if (!$has_chapters) {
        $reasons[] = "No active chapters";
    } else {
        $has_curriculum = false;
        foreach ($c->chapters()->where('is_active', 1)->get() as $ch) {
            if ($ch->lectures()->where('is_active', 1)->exists() || 
                $ch->quizzes()->where('is_active', 1)->exists() ||
                $ch->assignments()->where('is_active', 1)->exists() ||
                $ch->resources()->where('is_active', 1)->exists()) {
                $has_curriculum = true;
                break;
            }
        }
        if (!$has_curriculum) $reasons[] = "No active curriculum items";
    }
    
    if (empty($reasons)) {
        echo "ID: $c->id | Title: $c->title | Status: PASS\n";
    } else {
        echo "ID: $c->id | Title: $c->title | Status: FAIL | Reasons: " . implode(', ', $reasons) . "\n";
    }
}
