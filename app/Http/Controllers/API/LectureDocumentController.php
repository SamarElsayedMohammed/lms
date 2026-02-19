<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\CourseLectureDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LectureDocumentController extends Controller
{
    /**
     * Store a new document for a lecture.
     */
    public function store(Request $request, $courseId, $chapterId, $lectureId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to add documents to this lecture. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);

        // Check if lecture type is document
        if ($lecture->type !== 'document') {
            return response()->json([
                'status' => 'error',
                'message' => 'This lecture is not of type document',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'files' => 'required|array',
            'files.*' => 'required|file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt|max:10240',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $urls = [];

        // Upload files
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('course_documents/' . $courseId . '/' . $chapterId . '/' . $lectureId, 'public');
                $urls[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'type' => $file->getClientMimeType(),
                ];
            }
        }

        $data = $validator->validated();
        $data['course_chapter_lecture_id'] = $lectureId;
        $data['url'] = $urls;

        // If order is not provided, make it the last one
        if (!isset($data['order'])) {
            $data['order'] = $lecture->documents()->max('order') + 1;
        }

        // Create document
        $document = CourseLectureDocument::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Document created successfully',
            'data' => $document,
        ], 201);
    }

    /**
     * Display the specified document.
     */
    public function show($courseId, $chapterId, $lectureId, $documentId)
    {
        $course = Course::findOrFail($courseId);
        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);
        $document = $lecture->documents()->findOrFail($documentId);

        return response()->json([
            'status' => 'success',
            'data' => $document,
        ]);
    }

    /**
     * Update the specified document.
     */
    public function update(Request $request, $courseId, $chapterId, $lectureId, $documentId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to update this document. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);
        $document = $lecture->documents()->findOrFail($documentId);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'files' => 'sometimes|array',
            'files.*' => 'required|file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt|max:10240',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Handle file uploads if there are new files
        if ($request->hasFile('files')) {
            $urls = $document->url;

            foreach ($request->file('files') as $file) {
                $path = $file->store('course_documents/' . $courseId . '/' . $chapterId . '/' . $lectureId, 'public');
                $urls[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'size' => $file->getSize(),
                    'type' => $file->getClientMimeType(),
                ];
            }

            $data['url'] = $urls;
        }

        // Update document
        $document->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Document updated successfully',
            'data' => $document,
        ]);
    }

    /**
     * Remove the specified document.
     */
    public function destroy($courseId, $chapterId, $lectureId, $documentId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to delete this document. Only course owners and team members can modify course content.',
            ], 403);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);
        $document = $lecture->documents()->findOrFail($documentId);

        // Delete files from storage
        foreach ($document->url as $file) {
            if (!isset($file['path'])) {
                continue;
            }

            Storage::disk('public')->delete($file['path']);
        }

        // Delete document
        $document->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Document deleted successfully',
        ]);
    }

    /**
     * Remove a specific file from a document.
     */
    public function removeFile(Request $request, $courseId, $chapterId, $lectureId, $documentId)
    {
        $course = Course::findOrFail($courseId);

        // Check if user is the instructor of this course
        if (Auth::user()->cannot('modify', $course)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to remove files from this document. Only course owners and team members can modify course content.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'file_path' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $chapter = $course->chapters()->findOrFail($chapterId);
        $lecture = $chapter->lectures()->findOrFail($lectureId);
        $document = $lecture->documents()->findOrFail($documentId);

        $filePath = $request->file_path;
        $urls = $document->url;
        $fileIndex = -1;

        // Find the file to remove
        foreach ($urls as $index => $file) {
            if (!(isset($file['path']) && $file['path'] === $filePath)) {
                continue;
            }

            $fileIndex = $index;
            break;
        }

        if ($fileIndex === -1) {
            return response()->json([
                'status' => 'error',
                'message' => 'File not found in this document',
            ], 404);
        }

        // Delete file from storage
        Storage::disk('public')->delete($filePath);

        // Remove file from url array
        unset($urls[$fileIndex]);
        $urls = array_values($urls);

        // Update document
        $document->update(['url' => $urls]);

        return response()->json([
            'status' => 'success',
            'message' => 'File removed successfully',
            'data' => $document,
        ]);
    }
}
