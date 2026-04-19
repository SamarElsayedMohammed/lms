<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$q1 = App\Models\Course\Course::where('is_active', 1)
    ->where('status', 'publish')
    ->where('approval_status', 'approved')
    ->count();
echo "Base active/publish/approved: " . $q1 . "\n";

$q2 = App\Models\Course\Course::where('is_active', 1)
    ->where('status', 'publish')
    ->where('approval_status', 'approved')
    ->whereHas('user', function ($userQuery) {
        $userQuery
            ->where('is_active', 1) // User should be active
            ->where(function ($query) {
                // If user has instructor_details, it should be approved
                $query
                    ->whereHas('instructor_details', function ($instructorQuery) {
                        $instructorQuery->where('status', 'approved');
                    })
                    // OR if user is Admin, allow (Admin doesn't have instructor_details)
                    ->orWhereHas('roles', function ($roleQuery) {
                        $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                    });
            });
    })->count();
echo "With active user/instructor/admin: " . $q2 . "\n";

$q3 = App\Models\Course\Course::where('is_active', 1)
    ->where('status', 'publish')
    ->where('approval_status', 'approved')
    ->whereHas('user', function ($userQuery) {
        $userQuery
            ->where('is_active', 1) // User should be active
            ->where(function ($query) {
                // If user has instructor_details, it should be approved
                $query
                    ->whereHas('instructor_details', function ($instructorQuery) {
                        $instructorQuery->where('status', 'approved');
                    })
                    // OR if user is Admin, allow (Admin doesn't have instructor_details)
                    ->orWhereHas('roles', function ($roleQuery) {
                        $roleQuery->where('name', config('constants.SYSTEM_ROLES.ADMIN'));
                    });
            });
    })
    ->whereHas('chapters', function ($chapterQuery) {
        $chapterQuery
            ->where('is_active', true)
            ->where(function ($curriculumQuery) {
                $curriculumQuery
                    ->whereHas('lectures', function ($lectureQuery) {
                        $lectureQuery->where('is_active', true);
                    })
                    ->orWhereHas('quizzes', function ($quizQuery) {
                        $quizQuery->where('is_active', true);
                    })
                    ->orWhereHas('assignments', function ($assignmentQuery) {
                        $assignmentQuery->where('is_active', true);
                    })
                    ->orWhereHas('resources', function ($resourceQuery) {
                        $resourceQuery->where('is_active', true);
                    });
            });
    })->count();
echo "With chapters and curriculum: " . $q3 . "\n";
