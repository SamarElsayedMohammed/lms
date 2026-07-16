<?php

namespace App\Console\Commands;

use App\Models\Course\Course;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCourseDurations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'courses:backfill-durations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfills course, chapter, and lecture duration_seconds from existing legacy columns.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting backfill for course durations...');

        // 1. Backfill Lectures
        $this->info('Backfilling lectures...');
        $affectedLectures = DB::update('
            UPDATE course_chapter_lectures 
            SET duration_seconds = (COALESCE(hours, 0) * 3600) + (COALESCE(minutes, 0) * 60) + COALESCE(seconds, 0)
        ');
        $this->info("Updated {$affectedLectures} lectures.");

        // 2. Backfill Chapters
        $this->info('Backfilling chapters...');
        $chaptersQuery = DB::table('course_chapters')
            ->whereNull('deleted_at');
        
        $chapterCount = 0;
        $chaptersQuery->orderBy('id')->chunk(100, function ($chapters) use (&$chapterCount) {
            foreach ($chapters as $chapter) {
                $totalSeconds = DB::table('course_chapter_lectures')
                    ->where('course_chapter_id', $chapter->id)
                    ->whereNull('deleted_at')
                    ->sum('duration_seconds');

                DB::table('course_chapters')
                    ->where('id', $chapter->id)
                    ->update(['duration_seconds' => $totalSeconds]);
                
                $chapterCount++;
            }
        });
        $this->info("Updated {$chapterCount} chapters.");

        // 3. Backfill Courses
        $this->info('Backfilling courses...');
        $coursesQuery = Course::query()->withTrashed();

        $courseCount = 0;
        $missingLecturesCount = 0;

        $coursesQuery->chunk(50, function ($courses) use (&$courseCount, &$missingLecturesCount) {
            foreach ($courses as $course) {
                // Calculate total duration from chapters
                $totalSeconds = DB::table('course_chapters')
                    ->where('course_id', $course->id)
                    ->whereNull('deleted_at')
                    ->sum('duration_seconds');

                // Calculate total lectures count
                $lecturesCount = DB::table('course_chapters')
                    ->join('course_chapter_lectures', 'course_chapters.id', '=', 'course_chapter_lectures.course_chapter_id')
                    ->where('course_chapters.course_id', $course->id)
                    ->whereNull('course_chapters.deleted_at')
                    ->whereNull('course_chapter_lectures.deleted_at')
                    ->count();

                if ($lecturesCount === 0) {
                    $missingLecturesCount++;
                }

                DB::table('courses')
                    ->where('id', $course->id)
                    ->update([
                        'duration_seconds' => $totalSeconds,
                        'lectures_count' => $lecturesCount,
                    ]);

                $courseCount++;
            }
        });

        $this->info("Updated {$courseCount} courses.");
        $this->info("Found {$missingLecturesCount} courses with 0 lectures.");
        $this->info('Backfill completed successfully!');
    }
}
