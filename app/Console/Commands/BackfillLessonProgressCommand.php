<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\LessonProgress;
use App\Models\User;
use App\Models\VideoProgress;
use App\Services\LessonProgressService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BackfillLessonProgressCommand extends Command
{
    protected $signature = 'lms:backfill-lesson-progress {--course= : Specific course ID to backfill} {--dry-run : Run in simulation mode without writing to database}';

    protected $description = 'Safely and idempotently backfill lesson_progress from legacy video_progress records';

    public function handle(LessonProgressService $lessonProgressService): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $this->info('Starting lesson_progress backfill' . ($isDryRun ? ' [DRY RUN]...' : '...'));

        if (!Schema::hasTable('lesson_progress') || !Schema::hasTable('video_progress')) {
            $this->error('Required tables do not exist. Please run migrations first.');
            return Command::FAILURE;
        }

        $courseFilter = $this->option('course');

        $query = DB::table('video_progress')
            ->join('course_chapter_lectures', 'video_progress.lecture_id', '=', 'course_chapter_lectures.id')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->select([
                'video_progress.*',
                'course_chapters.course_id as resolved_course_id',
            ]);

        if ($courseFilter) {
            $query->where('course_chapters.course_id', (int) $courseFilter);
        }

        $records = $query->get();
        $total = $records->count();
        $this->info("Found {$total} video_progress records to process.");

        if ($isDryRun) {
            $this->warn("Dry run mode enabled: {$total} records would be backfilled. No database changes made.");

            // 1. Counts per course
            $courseBreakdown = $records->groupBy('resolved_course_id')->map(function ($group, $courseId) {
                return [
                    'course_id'       => $courseId,
                    'total_records'   => $group->count(),
                    'unique_students' => $group->pluck('user_id')->unique()->count(),
                    'completed'       => $group->where('is_completed', 1)->count(),
                ];
            })->values()->toArray();

            $this->table(['Course ID', 'Total Records', 'Unique Students', 'Completed'], $courseBreakdown);

            // 2. Spot check of 10 students
            $spotCheck = $records->take(10)->map(function ($rec) {
                return [
                    'student_id'   => $rec->user_id,
                    'course_id'    => $rec->resolved_course_id,
                    'lecture_id'   => $rec->lecture_id,
                    'watched_sec'  => (int) ($rec->watched_seconds ?? 0),
                    'percent'      => (float) ($rec->watch_percentage ?? 0.0),
                    'is_completed' => (bool) ($rec->is_completed ?? false) ? 'YES' : 'NO',
                ];
            })->toArray();

            $this->info('Spot check of 10 student records:');
            $this->table(['Student ID', 'Course ID', 'Lecture ID', 'Watched Sec', 'Percent', 'Completed'], $spotCheck);

            return Command::SUCCESS;
        }

        $processed = 0;
        $affectedUsersAndCourses = [];

        foreach ($records as $record) {
            $courseId = (int) $record->resolved_course_id;
            $userId = (int) $record->user_id;
            $lessonId = (int) $record->lecture_id;

            LessonProgress::updateOrCreate(
                [
                    'user_id'   => $userId,
                    'lesson_id' => $lessonId,
                ],
                [
                    'course_id'             => $courseId,
                    'last_position_seconds' => (int) ($record->last_position ?? 0),
                    'max_position_seconds'  => (int) ($record->last_position ?? 0),
                    'watched_seconds'       => (int) ($record->watched_seconds ?? 0),
                    'percent'               => (float) ($record->watch_percentage ?? 0.0),
                    'is_completed'          => (bool) ($record->is_completed ?? false),
                    'completed_at'          => $record->completed_at,
                    'last_heartbeat_at'     => $record->updated_at,
                ]
            );

            $key = "{$userId}:{$courseId}";
            $affectedUsersAndCourses[$key] = ['user_id' => $userId, 'course_id' => $courseId];
            $processed++;
        }

        $this->info("Successfully synced {$processed} lesson progress records.");

        // Recompute course progress aggregates for affected user/course combinations
        $this->info('Recomputing course progress aggregates...');
        foreach ($affectedUsersAndCourses as $item) {
            $user = User::find($item['user_id']);
            if ($user) {
                $lessonProgressService->recomputeCourseProgressAndCheckCertificate($user, (int) $item['course_id']);
            }
        }

        $this->info('Backfill completed successfully.');
        return Command::SUCCESS;
    }
}
