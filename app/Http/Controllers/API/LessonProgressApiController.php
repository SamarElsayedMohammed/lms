<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\LessonHeartbeatRequest;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Services\ContentAccessService;
use App\Services\LessonProgressService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class LessonProgressApiController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly LessonProgressService $lessonProgressService,
        private readonly ContentAccessService $contentAccessService,
    ) {}

    /**
     * Get saved progress for a specific lesson (with server-side sequential lock check).
     */
    public function getProgress(Request $request, int|string $lessonId): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        $lesson = CourseChapterLecture::find($lessonId);
        if ($lesson === null) {
            return $this->notFound('Lesson not found');
        }

        if (!$this->contentAccessService->canAccessLecture($user, $lesson)) {
            Log::warning('Lesson progress query rejected: UNENROLLED', [
                'user_id' => $user->id,
                'lesson_id' => (int) $lesson->id,
                'course_id' => (int) ($lesson->chapter?->course_id ?? 0),
            ]);
            return $this->forbidden('Course access required');
        }

        if (!$this->lessonProgressService->canAccessLesson($user, $lesson)) {
            Log::warning('Lesson progress query rejected: LOCKED', [
                'user_id' => $user->id,
                'lesson_id' => (int) $lesson->id,
                'course_id' => (int) ($lesson->chapter?->course_id ?? 0),
            ]);
            return response()->json([
                'success' => false,
                'error'   => true,
                'code'    => 'LESSON_LOCKED',
                'message' => 'يجب إكمال الدرس السابق أولاً للوصول إلى هذا المحتوى.',
            ], 403);
        }

        $progress = $this->lessonProgressService->getLessonProgress($user->id, $lesson);

        return $this->ok(
            data: [
                'lesson_id'             => (int) $lesson->id,
                'last_position'         => (int) $progress->last_position_seconds,
                'last_position_seconds' => (int) $progress->last_position_seconds,
                'max_position_seconds'  => (int) $progress->max_position_seconds,
                'watched_seconds'       => (int) $progress->watched_seconds,
                'percent'               => (float) $progress->percent,
                'watch_percentage'      => (float) $progress->percent,
                'is_completed'          => (bool) $progress->is_completed,
                'completed'             => (bool) $progress->is_completed,
                'completed_at'          => $progress->completed_at?->toIso8601String(),
                'can_seek_to'           => (int) $progress->max_position_seconds,
                'duration_seconds'      => (int) ($lesson->duration_seconds ?? $lesson->duration ?? 0),
                'total_seconds'         => (int) ($lesson->duration_seconds ?? $lesson->duration ?? 0),
                'resume_from'           => (int) $progress->last_position_seconds,
            ],
            meta: ['success' => true]
        );
    }

    /**
     * Heartbeat progress update (Idempotent, Server-Authoritative, Anti-Cheat).
     */
    public function heartbeat(LessonHeartbeatRequest $request, int|string $lessonId): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        $lesson = CourseChapterLecture::find($lessonId);
        if ($lesson === null) {
            return $this->notFound('Lesson not found');
        }

        if (!$this->contentAccessService->canAccessLecture($user, $lesson)) {
            Log::warning('Lesson heartbeat rejected: UNENROLLED', [
                'user_id' => $user->id,
                'lesson_id' => (int) $lesson->id,
                'course_id' => (int) ($lesson->chapter?->course_id ?? 0),
            ]);
            return $this->forbidden('Course access required');
        }

        if (!$this->lessonProgressService->canAccessLesson($user, $lesson)) {
            Log::warning('Lesson heartbeat rejected: LOCKED', [
                'user_id' => $user->id,
                'lesson_id' => (int) $lesson->id,
                'course_id' => (int) ($lesson->chapter?->course_id ?? 0),
            ]);
            return response()->json([
                'success' => false,
                'error'   => true,
                'code'    => 'LESSON_LOCKED',
                'message' => 'يجب إكمال الدرس السابق أولاً للوصول إلى هذا المحتوى.',
            ], 403);
        }

        $currentTime = (float) $request->input('current_time');
        $duration = (float) $request->input('duration');
        $event = (string) $request->input('event');

        $metadata = [
            'session_id' => $request->input('session_id'),
            'device'     => $request->input('device'),
            'browser'    => $request->input('browser'),
            'ip'         => $request->ip(),
        ];

        $progress = $this->lessonProgressService->recordHeartbeat(
            $user,
            $lesson,
            $currentTime,
            $duration,
            $event,
            $metadata
        );

        return $this->ok(
            data: [
                'lesson_id'             => (int) $lesson->id,
                'last_position'         => (int) $progress->last_position_seconds,
                'last_position_seconds' => (int) $progress->last_position_seconds,
                'max_position_seconds'  => (int) $progress->max_position_seconds,
                'watched_seconds'       => (int) $progress->watched_seconds,
                'percent'               => (float) $progress->percent,
                'watch_percentage'      => (float) $progress->percent,
                'is_completed'          => (bool) $progress->is_completed,
                'completed'             => (bool) $progress->is_completed,
                'completed_at'          => $progress->completed_at?->toIso8601String(),
                'can_seek_to'           => (int) $progress->max_position_seconds,
            ],
            message: 'Progress updated successfully',
            meta: ['success' => true]
        );
    }

    /**
     * Get course progress overview with sequential lock state for all lessons.
     */
    public function getCourseProgress(Request $request, int|string $courseId): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return $this->unauthorized();
        }

        $course = Course::find($courseId);
        if ($course === null) {
            return $this->notFound('Course not found');
        }

        if (!$this->contentAccessService->canAccessCourse($user, $course)) {
            return $this->forbidden('Course access required');
        }

        $overview = $this->lessonProgressService->getCourseProgressOverview($user, $course);

        return $this->ok(data: $overview, meta: ['success' => true]);
    }
}
