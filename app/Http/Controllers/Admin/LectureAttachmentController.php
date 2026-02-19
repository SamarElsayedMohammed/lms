<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\LectureAttachment;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LectureAttachmentController extends Controller
{
    /**
     * Store a new attachment for a lecture.
     */
    public function store(Request $request, int $lectureId): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:51200', // 50MB
        ]);

        $lecture = CourseChapterLecture::find($lectureId);
        if ($lecture === null) {
            return response()->json([
                'error' => true,
                'message' => 'Lecture not found',
                'code' => 404,
            ], 404);
        }

        $file = $request->file('file');
        $path = FileService::upload($file, 'lecture_attachments');

        $attachment = LectureAttachment::create([
            'lecture_id' => $lectureId,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'file_type' => $file->getMimeType(),
            'sort_order' => LectureAttachment::where('lecture_id', $lectureId)->max('sort_order') + 1,
        ]);

        return response()->json([
            'error' => false,
            'message' => 'Attachment uploaded',
            'data' => [
                'id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'file_url' => $attachment->file_url,
            ],
            'code' => 201,
        ], 201);
    }

    /**
     * Delete an attachment.
     */
    public function destroy(int $lectureId, int $attachmentId): JsonResponse
    {
        $attachment = LectureAttachment::where('lecture_id', $lectureId)
            ->where('id', $attachmentId)
            ->first();

        if ($attachment === null) {
            return response()->json([
                'error' => true,
                'message' => 'Attachment not found',
                'code' => 404,
            ], 404);
        }

        FileService::delete($attachment->file_path);
        $attachment->delete();

        return response()->json([
            'error' => false,
            'message' => 'Attachment deleted',
            'code' => 200,
        ]);
    }
}
