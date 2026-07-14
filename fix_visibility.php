<?php

$files = [
    __DIR__ . '/app/Http/Controllers/API/CourseApiController.php',
    __DIR__ . '/app/Http/Controllers/API/HomeApiController.php',
    __DIR__ . '/app/Http/Controllers/API/InstructorApiController.php',
    __DIR__ . '/app/Http/Controllers/API/DashboardController.php',
    __DIR__ . '/app/Http/Controllers/API/CourseChapterApiController.php'
];

$rolePattern = '/->whereHas\(\'user\', static function \(\$userQuery\): void \{.*?->orWhereHas\(\'roles\', static function \(\$roleQuery\): void \{\s*\$roleQuery->where\(\'name\', config\(\'constants\.SYSTEM_ROLES\.SUPER_ADMIN\'\)\);\s*\}\);\s*\}\);\s*\}/s';

$roleReplacement = "->whereHas('user', static function (\$userQuery): void {
                \$userQuery
                    ->where('is_active', 1)
                    ->where(static function (\$query): void {
                        \$query
                            ->whereHas('instructor_details', static function (\$instructorQuery): void {
                                \$instructorQuery->where('status', 'approved');
                            })
                            ->orWhereHas('roles', static function (\$roleQuery): void {
                                \$roleQuery->whereIn('name', [
                                    config('constants.SYSTEM_ROLES.SUPER_ADMIN'),
                                    config('constants.SYSTEM_ROLES.SUPERVISOR'),
                                    config('constants.SYSTEM_ROLES.TEAM'),
                                    config('constants.SYSTEM_ROLES.TEAM_INSTRUCTOR'),
                                    config('constants.SYSTEM_ROLES.STAFF'),
                                    config('constants.SYSTEM_ROLES.MODERATOR'),
                                ]);
                            });
                    });
            }";

$chapterPattern = '/\s*->whereHas\(\'chapters\', static function \(\$chapterQuery\): void \{\s*\$chapterQuery\s*->where\(\'is_active\', true\)\s*->where\(static function \(\$curriculumQuery\): void \{.*?\}\);\s*\}\)/s';
$chapterPatternAlternative = '/\s*->whereHas\(\'chapters\', static function \(\$query\): void \{\s*\$query\s*->where\(\'is_active\', true\)\s*->where\(static function \(\$curriculumQuery\): void \{.*?\}\);\s*\}\)/s';

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    
    $content = file_get_contents($file);
    $original = $content;
    
    // Fix role check
    $content = preg_replace($rolePattern, $roleReplacement, $content);
    
    // Fix chapters check
    $content = preg_replace($chapterPattern, "", $content);
    $content = preg_replace($chapterPatternAlternative, "", $content);
    
    if ($content !== $original) {
        file_put_contents($file, $content);
        echo "Updated $file\n";
    }
}
