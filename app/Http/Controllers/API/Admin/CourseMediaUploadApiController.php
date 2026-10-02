<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Services\CourseMediaStaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CourseMediaUploadApiController extends AdminCrudApiController
{
    /**
     * Accept one piece of a course video. The full file is assembled later
     * and uploaded to Bunny by ProcessCourseMediaUploadJob.
     */
    public function storeChunk(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'upload_id' => 'required|uuid',
            'index' => 'required|integer|min:0|max:160',
            'total' => 'required|integer|min:1|max:160',
            'chunk' => 'required|file|max:20480',
        ]);

        $index = (int) $validated['index'];
        $total = (int) $validated['total'];
        if ($index >= $total) {
            return $this->jsonError('رقم جزء الفيديو غير صحيح.', 422);
        }

        $chunk = $request->file('chunk');
        if ($chunk === null || ! $chunk->isValid()) {
            return $this->jsonError('تعذر قراءة جزء الفيديو.', 422);
        }

        CourseMediaStaging::storeChunk((string) $validated['upload_id'], $index, $chunk);

        return $this->jsonSuccess('تم استلام جزء الفيديو', [
            'upload_id' => $validated['upload_id'],
            'index' => $index,
            'total' => $total,
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'upload_id' => 'required|uuid',
            'total' => 'required|integer|min:1|max:160',
            'filename' => 'required|string|max:255',
            'mime' => 'nullable|string|max:150',
        ]);

        $userId = (int) Auth::id();
        $staged = CourseMediaStaging::assemble(
            $userId,
            (string) $validated['upload_id'],
            (int) $validated['total'],
            (string) $validated['filename'],
            (string) ($validated['mime'] ?? ''),
        );

        return $this->jsonSuccess('تم تجهيز الفيديو. سيتم رفعه على Bunny بعد حفظ الدورة.', [
            'upload_id' => $validated['upload_id'],
            'original_name' => $staged['original_name'],
            'bytes' => filesize($staged['path']) ?: 0,
        ]);
    }
}
