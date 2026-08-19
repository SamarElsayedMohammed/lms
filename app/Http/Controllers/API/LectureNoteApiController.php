<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Lecture\CourseLectureNote;
use App\Services\ContentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class LectureNoteApiController extends Controller
{
    public function __construct(
        protected ContentAccessService $contentAccessService
    ) {}

    /**
     * List user notes for a given lecture.
     */
    public function index(Request $request, int $lectureId): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $lecture = CourseChapterLecture::with('chapter.course')->find($lectureId);
        if (!$lecture) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Lecture not found.',
            ], 404);
        }

        if (!$this->contentAccessService->canAccessLecture($user, $lecture)) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Access denied to lecture notes.',
            ], 403);
        }

        $notes = CourseLectureNote::where('user_id', $user->id)
            ->where('lecture_id', $lectureId)
            ->orderBy('video_timestamp_seconds', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'status'  => true,
            'message' => 'Lecture notes retrieved successfully.',
            'data'    => [
                'notes' => $notes->map(fn (CourseLectureNote $note) => [
                    'id'                      => $note->id,
                    'video_timestamp_seconds' => $note->video_timestamp_seconds,
                    'note_text'               => $note->note_text,
                    'created_at'              => $note->created_at?->toISOString(),
                    'updated_at'              => $note->updated_at?->toISOString(),
                ]),
            ],
        ]);
    }

    /**
     * Store a timestamped video note.
     */
    public function store(Request $request, int $lectureId): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $lecture = CourseChapterLecture::with('chapter.course')->find($lectureId);
        if (!$lecture) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Lecture not found.',
            ], 404);
        }

        if (!$this->contentAccessService->canAccessLecture($user, $lecture)) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Access denied to create notes on this lecture.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'video_timestamp_seconds' => 'required|integer|min:0',
            'note_text'               => 'required|string|min:1|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $courseId = $lecture->chapter?->course_id;
        if (!$courseId) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Associated course not found.',
            ], 422);
        }

        $timestamp = (int) $request->input('video_timestamp_seconds');
        if ($lecture->duration_seconds > 0 && $timestamp > $lecture->duration_seconds) {
            $timestamp = $lecture->duration_seconds;
        }

        $note = CourseLectureNote::create([
            'user_id'                 => $user->id,
            'course_id'               => $courseId,
            'lecture_id'              => $lectureId,
            'video_timestamp_seconds' => $timestamp,
            'note_text'               => trim((string) $request->input('note_text')),
        ]);

        return response()->json([
            'success' => true,
            'status'  => true,
            'message' => 'Note created successfully.',
            'data'    => [
                'note' => [
                    'id'                      => $note->id,
                    'video_timestamp_seconds' => $note->video_timestamp_seconds,
                    'note_text'               => $note->note_text,
                    'created_at'              => $note->created_at?->toISOString(),
                    'updated_at'              => $note->updated_at?->toISOString(),
                ],
            ],
        ], 201);
    }

    /**
     * Update an existing note owned by the user.
     */
    public function update(Request $request, int $noteId): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $note = CourseLectureNote::find($noteId);
        if (!$note) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Note not found.',
            ], 404);
        }

        if ((int) $note->user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Unauthorized to update this note.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'note_text'               => 'required|string|min:1|max:5000',
            'video_timestamp_seconds' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $note->note_text = trim((string) $request->input('note_text'));
        if ($request->filled('video_timestamp_seconds')) {
            $note->video_timestamp_seconds = (int) $request->input('video_timestamp_seconds');
        }
        $note->save();

        return response()->json([
            'success' => true,
            'status'  => true,
            'message' => 'Note updated successfully.',
            'data'    => [
                'note' => [
                    'id'                      => $note->id,
                    'video_timestamp_seconds' => $note->video_timestamp_seconds,
                    'note_text'               => $note->note_text,
                    'created_at'              => $note->created_at?->toISOString(),
                    'updated_at'              => $note->updated_at?->toISOString(),
                ],
            ],
        ]);
    }

    /**
     * Delete a note owned by the user.
     */
    public function destroy(Request $request, int $noteId): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $note = CourseLectureNote::find($noteId);
        if (!$note) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Note not found.',
            ], 404);
        }

        if ((int) $note->user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Unauthorized to delete this note.',
            ], 403);
        }

        $note->delete();

        return response()->json([
            'success' => true,
            'status'  => true,
            'message' => 'Note deleted successfully.',
        ]);
    }
}
