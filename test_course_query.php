<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Course\Course;

$allCourses = Course::count();
echo "Total Courses: " . $allCourses . "\n";

$activeCourses = Course::where('is_active', 1)->count();
echo "Active Courses: " . $activeCourses . "\n";

$publishedCourses = Course::where('is_active', 1)->where('status', 'publish')->count();
echo "Published Courses: " . $publishedCourses . "\n";

$approvedCourses = Course::where('is_active', 1)->where('status', 'publish')->where('approval_status', 'approved')->count();
echo "Approved Courses: " . $approvedCourses . "\n";

$withValidUser = Course::where('is_active', 1)
    ->where('status', 'publish')
    ->where('approval_status', 'approved')
    ->whereHas('user', function ($userQuery) {
        $userQuery->where('is_active', 1)
            ->where(function ($query) {
                $query->whereHas('instructor_details', function ($instructorQuery) {
                    $instructorQuery->where('status', 'approved');
                })->orWhereHas('roles', function ($roleQuery) {
                    $roleQuery->where('name', config('constants.SYSTEM_ROLES.SUPER_ADMIN'));
                });
            });
    })->count();
echo "With Valid User: " . $withValidUser . "\n";

$withValidChapters = Course::where('is_active', 1)
    ->where('status', 'publish')
    ->where('approval_status', 'approved')
    ->whereHas('user', function ($userQuery) {
        $userQuery->where('is_active', 1)
            ->where(function ($query) {
                $query->whereHas('instructor_details', function ($instructorQuery) {
                    $instructorQuery->where('status', 'approved');
                })->orWhereHas('roles', function ($roleQuery) {
                    $roleQuery->where('name', config('constants.SYSTEM_ROLES.SUPER_ADMIN'));
                });
            });
    })
    ->whereHas('chapters', function ($chapterQuery) {
        $chapterQuery->where('is_active', true)
            ->where(function ($curriculumQuery) {
                $curriculumQuery->whereHas('lectures', function ($lectureQuery) {
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
echo "With Valid Chapters: " . $withValidChapters . "\n";

// Let's also test SUPER_ADMIN role name
echo "SUPER_ADMIN role name config: " . config('constants.SYSTEM_ROLES.SUPER_ADMIN') . "\n";
