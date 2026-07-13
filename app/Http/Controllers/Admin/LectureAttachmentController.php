<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\LectureAttachment;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LectureAttachmentController extends Controller
{
    /**
     * List attachments for a lecture.
     */
    public function index(int $lectureId): JsonResponse
    {
        $lecture = CourseChapterLecture::find($lectureId);
        if ($lecture === null) {
            return response()->json([
                'error' => true,
                'message' => 'Lecture not found',
                'code' => 404,
            ], 404);
        }

        $attachments = $lecture->attachments->map(fn ($attachment) => $this->formatAttachment($attachment));

        return response()->json([
            'error' => false,
            'message' => 'Success',
            'data' => [
                'attachments' => $attachments,
            ],
            'code' => 200,
        ]);
    }

    /**
     * Store a new attachment for a lecture.
     */
    public function store(Request $request, int $lectureId): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,doc,docx,ppt,pptx,zip,rar,jpeg,png,jpg,mp4,mp3,wav|max:51200', // 50MB
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
     * Show a single attachment.
     */
    public function show(int $lectureId, int $attachmentId): JsonResponse
    {
        $attachment = $this->findAttachment($lectureId, $attachmentId);

        if ($attachment === null) {
            return response()->json([
                'error' => true,
                'message' => 'Attachment not found',
                'code' => 404,
            ], 404);
        }

        return response()->json([
            'error' => false,
            'message' => 'Success',
            'data' => [
                'attachment' => $this->formatAttachment($attachment),
            ],
            'code' => 200,
        ]);
    }

    /**
     * Update an attachment file or sort order.
     */
    public function update(Request $request, int $lectureId, int $attachmentId): JsonResponse
    {
        $request->validate([
            'file' => 'nullable|file|max:51200', // 50MB
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $attachment = $this->findAttachment($lectureId, $attachmentId);

        if ($attachment === null) {
            return response()->json([
                'error' => true,
                'message' => 'Attachment not found',
                'code' => 404,
            ], 404);
        }

        $data = [];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = FileService::replace($file, 'lecture_attachments', $attachment->file_path);

            $data['file_name'] = $file->getClientOriginalName();
            $data['file_path'] = $path;
            $data['file_size'] = $file->getSize();
            $data['file_type'] = $file->getMimeType();
        }

        if ($request->has('sort_order')) {
            $data['sort_order'] = $request->integer('sort_order');
        }

        if ($data !== []) {
            $attachment->update($data);
            $attachment->refresh();
        }

        return response()->json([
            'error' => false,
            'message' => 'Attachment updated',
            'data' => [
                'attachment' => $this->formatAttachment($attachment),
            ],
            'code' => 200,
        ]);
    }

    /**
     * Delete an attachment.
     */
    public function destroy(int $lectureId, int $attachmentId): JsonResponse
    {
        $attachment = $this->findAttachment($lectureId, $attachmentId);

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

    private function findAttachment(int $lectureId, int $attachmentId): ?LectureAttachment
    {
        return LectureAttachment::where('lecture_id', $lectureId)
            ->where('id', $attachmentId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAttachment(LectureAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'lecture_id' => $attachment->lecture_id,
            'file_name' => $attachment->file_name,
            'file_url' => $attachment->file_url,
            'file_size' => $attachment->file_size,
            'file_type' => $attachment->file_type,
            'sort_order' => $attachment->sort_order,
            'created_at' => $attachment->created_at,
            'updated_at' => $attachment->updated_at,
        ];
    }
}
