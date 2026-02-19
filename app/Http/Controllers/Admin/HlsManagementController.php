<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EncodeVideoToHLS;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Setting;
use App\Services\CachingService;
use App\Services\FFmpegService;
use App\Services\ResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

final class HlsManagementController extends Controller
{
    private const array VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'webm', 'mkv', 'flv', 'wmv'];

    /**
     * Display the HLS management dashboard.
     */
    public function index(): View
    {
        ResponseService::noPermissionThenRedirect('settings-hls-list');

        $ffmpegStatus = FFmpegService::getStatus();
        $settings = CachingService::getSystemSettings(['hls_auto_encode', 'hls_max_file_size_mb']);

        // Get statistics using a single optimized query
        $stats = $this->getStatistics();

        return view('settings.hls-management', [
            'type_menu' => 'settings',
            'ffmpegStatus' => $ffmpegStatus,
            'settings' => $settings,
            'stats' => $stats,
        ]);
    }

    /**
     * Refresh FFmpeg status by clearing cache.
     */
    public function refreshStatus(): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('settings-hls-edit');

        FFmpegService::clearCache();
        $status = FFmpegService::getStatus();

        return response()->json([
            'error' => false,
            'message' => __('FFmpeg status refreshed successfully'),
            'data' => $status,
        ]);
    }

    /**
     * Get video lectures data for the table.
     */
    public function getVideos(Request $request): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('settings-hls-list');

        $query = CourseChapterLecture::query()
            ->with(['chapter.course:id,title'])
            ->where('type', 'file')
            ->whereIn('file_extension', self::VIDEO_EXTENSIONS);

        // Filter by status
        $status = $request->input('status');
        if ($status !== null && $status !== 'all') {
            if ($status === 'not_encoded') {
                $query->whereNull('hls_status');
            } else {
                $query->where('hls_status', $status);
            }
        }

        // Search by title or course name
        $search = $request->input('search');
        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q
                    ->where('title', 'like', "%{$search}%")
                    ->orWhereHas('chapter.course', function ($q) use ($search): void {
                        $q->where('title', 'like', "%{$search}%");
                    })
                    ->orWhereHas('chapter', function ($q) use ($search): void {
                        $q->where('title', 'like', "%{$search}%");
                    });
            });
        }

        // Sorting
        $sortField = $request->input('sort', 'created_at');
        $sortOrder = $request->input('order', 'desc');
        $allowedSortFields = ['id', 'title', 'hls_status', 'hls_encoded_at', 'created_at'];
        if (in_array($sortField, $allowedSortFields, true)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Pagination
        $limit = (int) $request->input('limit', 20);
        $offset = (int) $request->input('offset', 0);

        $total = $query->count();
        $lectures = $query->skip($offset)->take($limit)->get();

        $rows = $lectures->map(function (CourseChapterLecture $lecture): array {
            return [
                'id' => $lecture->id,
                'title' => $lecture->title,
                'course_name' => $lecture->chapter?->course?->title ?? __('N/A'),
                'chapter_name' => $lecture->chapter?->title ?? __('N/A'),
                'hls_status' => $lecture->hls_status,
                'hls_status_badge' => $this->getStatusBadge($lecture->hls_status),
                'hls_error_message' => $lecture->hls_error_message,
                'hls_encoded_at' => $lecture->hls_encoded_at?->format('Y-m-d H:i:s'),
                'file_extension' => $lecture->file_extension,
                'created_at' => $lecture->created_at?->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'total' => $total,
            'rows' => $rows,
        ]);
    }

    /**
     * Encode a video that hasn't been encoded yet.
     */
    public function encodeVideo(CourseChapterLecture $lecture): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('settings-hls-edit');

        if ($lecture->type !== 'file') {
            return response()->json([
                'error' => true,
                'message' => __('This lecture is not a file-type lecture'),
            ], 400);
        }

        if (!in_array($lecture->file_extension, self::VIDEO_EXTENSIONS, true)) {
            return response()->json([
                'error' => true,
                'message' => __('This lecture is not a video file'),
            ], 400);
        }

        if (!FFmpegService::isAvailable()) {
            return response()->json([
                'error' => true,
                'message' => __('FFmpeg is not available on this server'),
            ], 400);
        }

        $lecture->update([
            'hls_status' => 'pending',
            'hls_error_message' => null,
        ]);

        EncodeVideoToHLS::dispatch($lecture);

        return response()->json([
            'error' => false,
            'message' => __('Video encoding job has been queued'),
        ]);
    }

    /**
     * Retry encoding for a failed video.
     */
    public function retryEncoding(CourseChapterLecture $lecture): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('settings-hls-edit');

        if ($lecture->hls_status !== 'failed') {
            return response()->json([
                'error' => true,
                'message' => __('This video is not in failed status'),
            ], 400);
        }

        if (!FFmpegService::isAvailable()) {
            return response()->json([
                'error' => true,
                'message' => __('FFmpeg is not available on this server'),
            ], 400);
        }

        $lecture->update([
            'hls_status' => 'pending',
            'hls_error_message' => null,
        ]);

        EncodeVideoToHLS::dispatch($lecture);

        return response()->json([
            'error' => false,
            'message' => __('Video re-encoding job has been queued'),
        ]);
    }

    /**
     * Re-encode a completed video.
     */
    public function reencodeVideo(CourseChapterLecture $lecture): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('settings-hls-edit');

        if ($lecture->type !== 'file') {
            return response()->json([
                'error' => true,
                'message' => __('This lecture is not a file-type lecture'),
            ], 400);
        }

        if (!in_array($lecture->file_extension, self::VIDEO_EXTENSIONS, true)) {
            return response()->json([
                'error' => true,
                'message' => __('This lecture is not a video file'),
            ], 400);
        }

        if (!FFmpegService::isAvailable()) {
            return response()->json([
                'error' => true,
                'message' => __('FFmpeg is not available on this server'),
            ], 400);
        }

        $lecture->update([
            'hls_status' => 'pending',
            'hls_manifest_path' => null,
            'hls_error_message' => null,
            'hls_encoded_at' => null,
        ]);

        EncodeVideoToHLS::dispatch($lecture);

        return response()->json([
            'error' => false,
            'message' => __('Video re-encoding job has been queued'),
        ]);
    }

    /**
     * Bulk retry all failed videos.
     */
    public function bulkRetryFailed(): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('settings-hls-edit');

        if (!FFmpegService::isAvailable()) {
            return response()->json([
                'error' => true,
                'message' => __('FFmpeg is not available on this server'),
            ], 400);
        }

        $failedLectures = CourseChapterLecture::query()
            ->where('type', 'file')
            ->whereIn('file_extension', self::VIDEO_EXTENSIONS)
            ->where('hls_status', 'failed')
            ->get();

        $count = $failedLectures->count();

        if ($count === 0) {
            return response()->json([
                'error' => false,
                'message' => __('No failed videos to retry'),
            ]);
        }

        foreach ($failedLectures as $lecture) {
            $lecture->update([
                'hls_status' => 'pending',
                'hls_error_message' => null,
            ]);

            EncodeVideoToHLS::dispatch($lecture);
        }

        return response()->json([
            'error' => false,
            'message' => __(':count video(s) have been queued for re-encoding', ['count' => $count]),
        ]);
    }

    /**
     * Update HLS settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        ResponseService::noPermissionThenSendJson('settings-hls-edit');

        $validator = Validator::make($request->all(), [
            'hls_auto_encode' => 'required|boolean',
            'hls_max_file_size_mb' => 'required|integer|min:1|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => true,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $settingsArray = [
            [
                'name' => 'hls_auto_encode',
                'value' => $request->boolean('hls_auto_encode') ? '1' : '0',
                'type' => 'boolean',
            ],
            [
                'name' => 'hls_max_file_size_mb',
                'value' => (string) $request->input('hls_max_file_size_mb'),
                'type' => 'number',
            ],
        ];

        Setting::upsert($settingsArray, ['name']);

        // Clear settings cache
        CachingService::removeCache(config('constants.CACHE.SETTINGS'));

        return response()->json([
            'error' => false,
            'message' => __('HLS settings updated successfully'),
        ]);
    }

    /**
     * Get video encoding statistics using a single optimized query.
     *
     * @return array{total: int, pending: int, processing: int, completed: int, failed: int, not_encoded: int}
     */
    private function getStatistics(): array
    {
        $stats = CourseChapterLecture::query()
            ->where('type', 'file')
            ->whereIn('file_extension', self::VIDEO_EXTENSIONS)
            ->selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN hls_status = "pending" THEN 1 END) as pending,
                COUNT(CASE WHEN hls_status = "processing" THEN 1 END) as processing,
                COUNT(CASE WHEN hls_status = "completed" THEN 1 END) as completed,
                COUNT(CASE WHEN hls_status = "failed" THEN 1 END) as failed,
                COUNT(CASE WHEN hls_status IS NULL THEN 1 END) as not_encoded
            ')
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'processing' => (int) ($stats->processing ?? 0),
            'completed' => (int) ($stats->completed ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
            'not_encoded' => (int) ($stats->not_encoded ?? 0),
        ];
    }

    /**
     * Get the badge HTML for a given HLS status.
     */
    private function getStatusBadge(null|string $status): string
    {
        return match ($status) {
            'pending' => '<span class="badge badge-warning">' . __('Pending') . '</span>',
            'processing' => '<span class="badge badge-info">' . __('Processing') . '</span>',
            'completed' => '<span class="badge badge-success">' . __('Completed') . '</span>',
            'failed' => '<span class="badge badge-danger">' . __('Failed') . '</span>',
            default => '<span class="badge badge-secondary">' . __('Not Encoded') . '</span>',
        };
    }
}
