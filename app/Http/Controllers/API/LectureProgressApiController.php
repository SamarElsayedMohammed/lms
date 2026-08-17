<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Services\ContentAccessService;
use App\Services\CourseProgressService;
use App\Services\VideoProgressService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LectureProgressApiController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly VideoProgressService $videoProgressService,
        private readonly CourseProgressService $courseProgressService,
        private readonly ContentAccessService $contentAccessService,
    ) {}

    /**
     * Update video watch progress using segment or standard tracking.
     */
    public function updateProgress(Request $request, int $lectureId): JsonResponse
    {
        $lecture = CourseChapterLecture::find($lectureId);
        if ($lecture === null) {
            return $this->notFound('Lecture not found');
        }

        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        if (!$this->contentAccessService->canAccessLecture($user, $lecture)) {
            return $this->forbidden('Course access required');
        }

        $metadata = [
            'session_id' => $request->input('session_id'),
            'device' => $request->input('device'),
            'browser' => $request->input('browser'),
            'ip' => $request->ip(),
            'progress_state' => $request->input('progress_state', 'playing'),
        ];

        $hasSegments = $request->has('newly_watched_segments');

        if ($hasSegments) {
            $validated = $request->validate([
                'current_position' => 'required|integer|min:0',
                'total_duration' => 'required|integer|min:1',
                'newly_watched_segments' => 'required|array|max:' . VideoProgressService::MAX_SEGMENTS_PER_REQUEST,
                'newly_watched_segments.*' => 'integer|min:0',
            ]);

            $canonicalDuration = $this->videoProgressService->getCanonicalDuration($lecture);

            $progress = $this->videoProgressService->updateSegmentProgress(
                $user,
                $lecture,
                (int) $validated['current_position'],
                $canonicalDuration,
                $validated['newly_watched_segments'],
                $metadata
            );

            return $this->ok(
                data: [
                    'watch_percentage' => (float) $progress->watch_percentage,
                    'is_completed' => (bool) $progress->is_completed,
                    'completed_segments' => $progress->completed_segments,
                    'total_segments' => $progress->total_segments,
                    'last_position' => $progress->last_position,
                    'can_seek_to' => $this->videoProgressService->getMaxSeekablePosition($progress),
                ],
                message: 'Progress updated'
            );
        }

        // Standard watch-time tracking (e.g. from Flutter mobile app & standard web player)
        $validated = $request->validate([
            'watched_seconds' => 'nullable|integer|min:0',
            'last_position' => 'nullable|integer|min:0',
            'total_seconds' => 'nullable|integer|min:0',
            'current_position' => 'nullable|integer|min:0',
            'total_duration' => 'nullable|integer|min:0',
        ]);

        $lastPosition = (int) ($validated['last_position'] ?? $validated['current_position'] ?? 0);
        $watchedSeconds = (int) ($validated['watched_seconds'] ?? $lastPosition);

        $canonicalDuration = $this->videoProgressService->getCanonicalDuration($lecture);

        $progress = $this->videoProgressService->updateProgress(
            $user,
            $lecture,
            $watchedSeconds,
            $lastPosition,
            $canonicalDuration,
            $metadata
        );

        return $this->ok(
            data: [
                'watched_seconds' => $progress->watched_seconds,
                'total_seconds' => $progress->total_seconds,
                'last_position' => $progress->last_position,
                'watch_percentage' => (float) $progress->watch_percentage,
                'is_completed' => (bool) $progress->is_completed,
                'can_seek_to' => $this->videoProgressService->getMaxSeekablePosition($progress),
            ],
            message: 'Progress updated'
        );
    }

    /**
     * Get video progress for a lecture (with segment info).
     */
    public function getProgress(int $lectureId): JsonResponse
    {
        $lecture = CourseChapterLecture::find($lectureId);
        if ($lecture === null) {
            return $this->notFound('Lecture not found');
        }

        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        if (!$this->contentAccessService->canAccessLecture($user, $lecture)) {
            return $this->forbidden('Course access required');
        }

        $progress = $this->videoProgressService->getProgressWithSeekInfo($user, $lecture);

        if ($progress === null) {
            return $this->ok(data: [
                'watched_seconds' => 0,
                'total_seconds' => 0,
                'watch_percentage' => 0.0,
                'last_position' => 0,
                'is_completed' => false,
                'watched_segments' => [],
                'total_segments' => 0,
                'completed_segments' => 0,
                'can_seek_to' => 0,
                'resume_from' => 0,
            ]);
        }

        return $this->ok(data: $progress);
    }

    /**
     * Get full course progress breakdown.
     *
     * Uses CourseProgressService as the single source of truth for required
     * video lectures; retired assessment/resource nodes are not completion
     * authorities.
     */
    public function getCourseProgress(int $courseId): JsonResponse
    {
        $course = Course::find($courseId);
        if ($course === null) {
            return $this->notFound('Course not found');
        }

        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        if (!$this->contentAccessService->canAccessCourse($user, $course)) {
            return $this->forbidden('Course access required');
        }

        try {
            $detailed = $this->courseProgressService->getDetailedProgress($user->id, $courseId);
        } catch (\Throwable $e) {
            return $this->error('Failed to retrieve course progress');
        }

        // Flatten per-lecture detail for backward-compat with existing consumers.
        $lessons = [];
        foreach ($detailed['chapters'] as $chapter) {
            foreach ($chapter['items'] as $item) {
                if ($item['type'] !== 'lecture') {
                    continue;
                }
                $lessons[] = [
                    'lecture_id'       => $item['item_id'],
                    'title'            => $item['title'],
                    'watch_percentage' => $item['watch_percentage'] ?? 0,
                    'is_completed'     => $item['status'] === 'completed',
                ];
            }
        }

        return $this->ok(data: [
            'course_id'          => $courseId,
            'overall_percentage' => $detailed['course']['progress_percentage'],
            'status'             => $detailed['course']['status'],
            'completed_items'    => $detailed['summary']['completed_items'],
            'total_items'        => $detailed['summary']['total_items'],
            'next_item'          => $detailed['next_item'],
            'lessons'            => $lessons,
        ]);
    }
}
