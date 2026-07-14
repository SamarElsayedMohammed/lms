const fs = require('fs');
const path = require('path');

const files = [
    path.join(__dirname, 'app/Http/Controllers/API/CourseApiController.php'),
    path.join(__dirname, 'app/Http/Controllers/API/HomeApiController.php'),
    path.join(__dirname, 'app/Http/Controllers/API/InstructorApiController.php'),
    path.join(__dirname, 'app/Http/Controllers/API/DashboardController.php'),
    path.join(__dirname, 'app/Http/Controllers/API/CourseChapterApiController.php')
];

const rolePattern = /->whereHas\('user',\s*static\s*function\s*\(\$userQuery\):\s*void\s*\{[\s\S]*?->orWhereHas\('roles',\s*static\s*function\s*\(\$roleQuery\):\s*void\s*\{\s*\$roleQuery->where\('name',\s*config\('constants\.SYSTEM_ROLES\.SUPER_ADMIN'\)\);\s*\}\);\s*\}\);\s*\}/gs;

const roleReplacement = `->whereHas('user', static function ($userQuery): void {
                $userQuery
                    ->where('is_active', 1)
                    ->where(static function ($query): void {
                        $query
                            ->whereHas('instructor_details', static function ($instructorQuery): void {
                                $instructorQuery->where('status', 'approved');
                            })
                            ->orWhereHas('roles', static function ($roleQuery): void {
                                $roleQuery->whereIn('name', [
                                    config('constants.SYSTEM_ROLES.SUPER_ADMIN'),
                                    config('constants.SYSTEM_ROLES.SUPERVISOR'),
                                    config('constants.SYSTEM_ROLES.TEAM'),
                                    config('constants.SYSTEM_ROLES.TEAM_INSTRUCTOR'),
                                    config('constants.SYSTEM_ROLES.STAFF'),
                                    config('constants.SYSTEM_ROLES.MODERATOR'),
                                ]);
                            });
                    });
            }`;

const chapterPattern = /\s*->whereHas\('chapters',\s*static\s*function\s*\(\$(?:chapterQuery|query)\):\s*void\s*\{\s*\$(?:chapterQuery|query)\s*->where\('is_active',\s*true\)\s*->where\(static\s*function\s*\(\$curriculumQuery\):\s*void\s*\{[\s\S]*?\}\);\s*\}\)/gs;

for (const file of files) {
    if (!fs.existsSync(file)) continue;
    
    let content = fs.readFileSync(file, 'utf8');
    const original = content;
    
    // Fix role check
    content = content.replace(rolePattern, roleReplacement);
    
    // Fix chapters check
    content = content.replace(chapterPattern, "");
    
    if (content !== original) {
        fs.writeFileSync(file, content, 'utf8');
        console.log(`Updated ${file}`);
    } else {
        console.log(`No changes needed for ${file}`);
    }
}
