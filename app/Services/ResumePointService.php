<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz;
use App\Models\Course\CourseChapter\Resource\CourseChapterResource;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Models\VideoProgress;
use Carbon\Carbon;

/** Owns resume semantics. A start item is deliberately not a resume point. */
final class ResumePointService
{
    public function __construct(private readonly CourseProgressService $progressService) {}

    /** @param array<int, int> $courseIds */
    public function resolveLatest(User $user, array $courseIds): ?array
    {
        if ($courseIds === []) {
            return null;
        }

        $video = VideoProgress::query()
            ->join('course_chapter_lectures', 'video_progress.lecture_id', '=', 'course_chapter_lectures.id')
            ->join('course_chapters', 'course_chapter_lectures.course_chapter_id', '=', 'course_chapters.id')
            ->where('video_progress.user_id', $user->id)
            ->whereIn('course_chapters.course_id', $courseIds)
            ->where('video_progress.watched_seconds', '>', 0)
            ->where('course_chapter_lectures.is_active', true)
            ->where('course_chapters.is_active', true)
            ->select([
                'video_progress.id as activity_id',
                'video_progress.lecture_id as model_id',
                'video_progress.watched_seconds',
                'video_progress.last_position',
                'video_progress.is_completed',
                'video_progress.updated_at as activity_at',
                'course_chapter_lectures.title as item_title',
                'course_chapters.id as chapter_id',
                'course_chapters.title as chapter_title',
                'course_chapters.course_id',
            ])
            ->latest('video_progress.updated_at')
            ->first();

        $tracking = $this->latestValidTracking((int) $user->id, $courseIds);
        if ($video === null && $tracking === null) {
            return null;
        }

        if ($video !== null && ($tracking === null
            || Carbon::parse($video->activity_at)->gte(Carbon::parse($tracking['activity_at'])))) {
            return $this->formatVideo($user, $video);
        }

        return $this->formatTracking($user, $tracking);
    }

    public function resolveStartItem(Course $course): ?array
    {
        $chapter = $course->chapters()
            ->where('is_active', true)
            ->orderBy('chapter_order')
            ->first();
        if ($chapter === null) {
            return null;
        }

        $lecture = $chapter->lectures()
            ->where('is_active', true)
            ->orderBy('chapter_order')
            ->first();
        if ($lecture === null) {
            return null;
        }

        return [
            'lesson_id' => (int) $lecture->id,
            'lesson_title' => $lecture->title,
            'chapter_id' => (int) $chapter->id,
            'chapter_title' => $chapter->title,
            'model_id' => (int) $lecture->id,
            'model_type' => 'lecture',
        ];
    }

    /** @param array<int, int> $courseIds */
    private function latestValidTracking(int $userId, array $courseIds): ?array
    {
        $candidates = UserCurriculumTracking::query()
            ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->where('user_curriculum_trackings.user_id', $userId)
            ->whereIn('course_chapters.course_id', $courseIds)
            ->where('course_chapters.is_active', true)
            ->select([
                'user_curriculum_trackings.*',
                'course_chapters.course_id',
                'course_chapters.title as chapter_title',
            ])
            ->latest('user_curriculum_trackings.updated_at')
            ->limit(50)
            ->get();

        foreach ($candidates as $candidate) {
            [$item, $type] = $this->resolveTrackedItem((string) $candidate->model_type, (int) $candidate->model_id);
            if ($item === null || (isset($item->is_active) && !$item->is_active)) {
                continue;
            }

            return [
                'tracking' => $candidate,
                'item' => $item,
                'type' => $type,
                'activity_at' => $candidate->updated_at,
            ];
        }

        return null;
    }

    private function resolveTrackedItem(string $modelType, int $modelId): array
    {
        $map = [
            'CourseChapterLecture' => [CourseChapterLecture::class, 'lecture'],
            'CourseChapterQuiz' => [CourseChapterQuiz::class, 'quiz'],
            'CourseChapterAssignment' => [CourseChapterAssignment::class, 'assignment'],
            'CourseChapterResource' => [CourseChapterResource::class, 'resource'],
        ];

        foreach ($map as $suffix => [$class, $shortType]) {
            if ($modelType === $class || str_ends_with($modelType, $suffix)) {
                return [$class::find($modelId), $shortType];
            }
        }

        return [null, 'activity'];
    }

    private function formatVideo(User $user, object $video): array
    {
        $course = Course::find((int) $video->course_id);
        $detailed = $this->progressService->getDetailedProgress((int) $user->id, (int) $video->course_id);
        if ((bool) $video->is_completed && !empty($detailed['next_item'])) {
            return $this->formatNextLecture($course, $detailed, $detailed['next_item']);
        }

        return [
            'current_curriculum_id' => (int) $video->model_id,
            'curriculum_name' => $video->item_title,
            'lesson_id' => (int) $video->model_id,
            'lesson_title' => $video->item_title,
            'chapter_id' => (int) $video->chapter_id,
            'chapter_title' => $video->chapter_title,
            'course_id' => (int) $video->course_id,
            'course_title' => $course?->title,
            'course_slug' => $course?->slug,
            'model_id' => (int) $video->model_id,
            'model_type' => 'lecture',
            'model_type_full' => CourseChapterLecture::class,
            'resume_position_seconds' => (int) $video->last_position,
            'progress_percentage' => (float) ($detailed['course']['progress_percentage'] ?? 0),
            'completed_at' => null,
            'last_activity_at' => Carbon::parse($video->activity_at)->toIso8601String(),
        ];
    }

    private function formatTracking(User $user, array $resolved): array
    {
        $tracking = $resolved['tracking'];
        $item = $resolved['item'];
        $course = Course::find((int) $tracking->course_id);
        $detailed = $this->progressService->getDetailedProgress((int) $user->id, (int) $tracking->course_id);
        if ($tracking->status === 'completed' && !empty($detailed['next_item'])) {
            return $this->formatNextLecture($course, $detailed, $detailed['next_item']);
        }
        $itemTitle = $item->title ?? $item->name ?? null;

        return [
            'current_curriculum_id' => (int) $tracking->model_id,
            'curriculum_name' => $itemTitle,
            'lesson_id' => (int) $tracking->model_id,
            'lesson_title' => $itemTitle,
            'chapter_id' => (int) $tracking->course_chapter_id,
            'chapter_title' => $tracking->chapter_title,
            'course_id' => (int) $tracking->course_id,
            'course_title' => $course?->title,
            'course_slug' => $course?->slug,
            'model_id' => (int) $tracking->model_id,
            'model_type' => $resolved['type'],
            'model_type_full' => $tracking->model_type,
            'resume_position_seconds' => 0,
            'progress_percentage' => (float) ($detailed['course']['progress_percentage'] ?? 0),
            'completed_at' => $tracking->completed_at?->toIso8601String(),
            'last_activity_at' => Carbon::parse($tracking->updated_at)->toIso8601String(),
        ];
    }

    private function formatNextLecture(?Course $course, array $detailed, array $nextItem): array
    {
        $chapter = $course?->chapters()->find((int) $nextItem['chapter_id']);

        return [
            'current_curriculum_id' => (int) $nextItem['item_id'],
            'curriculum_name' => $nextItem['title'] ?? null,
            'lesson_id' => (int) $nextItem['item_id'],
            'lesson_title' => $nextItem['title'] ?? null,
            'chapter_id' => (int) $nextItem['chapter_id'],
            'chapter_title' => $chapter?->title,
            'course_id' => $course?->id,
            'course_title' => $course?->title,
            'course_slug' => $course?->slug,
            'model_id' => (int) $nextItem['item_id'],
            'model_type' => 'lecture',
            'model_type_full' => CourseChapterLecture::class,
            'resume_position_seconds' => 0,
            'progress_percentage' => (float) ($detailed['course']['progress_percentage'] ?? 0),
            'completed_at' => null,
            'last_activity_at' => null,
        ];
    }
}
