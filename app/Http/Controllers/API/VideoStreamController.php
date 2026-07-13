<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\OrderCourse;
use App\Services\ContentAccessService;
use App\Services\FeatureFlagService;
use App\Services\VideoProgressService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VideoStreamController extends Controller
{
    use HasApiResponse;

    private const int TOKEN_EXPIRY_SECONDS = 1800; // 30 minutes

    private const int ENROLLMENT_CACHE_SECONDS = 300; // 5 minutes

    public function __construct(
        private readonly ContentAccessService $contentAccessService,
        private readonly VideoProgressService $videoProgressService,
        private readonly FeatureFlagService $featureFlagService,
    ) {}

    /**
     * Resolve playable video for an authenticated user (HLS, direct file, or YouTube).
     */
    public function stream(int|string $lectureId): JsonResponse
    {
        $courseChapterLecture = CourseChapterLecture::query()
            ->with('chapter.course')
            ->findOrFail($lectureId);

        try {
            $user = Auth::user();
            if ($user === null) {
                return $this->unauthorized();
            }

            $isFreePreview = (bool) $courseChapterLecture->free_preview;

            if (!$isFreePreview) {
                if (!$this->contentAccessService->canAccessLecture($user, $courseChapterLecture)) {
                    return $this->forbidden('Subscription required');
                }

                if ($this->featureFlagService->isEnabled('video_progress_enforcement', true)) {
                    if (!$this->videoProgressService->canAccessNextLesson($user, $courseChapterLecture)) {
                        return $this->forbidden('Complete the previous lesson first (85% required)');
                    }
                }
            }

            if ($courseChapterLecture->hasHls()) {
                return $this->grantHlsStream($courseChapterLecture, $user, $isFreePreview);
            }

            if ($courseChapterLecture->type === 'youtube_url') {
                $videoUrl = $courseChapterLecture->youtube_url;
                $videoType = $this->detectExternalVideoType($videoUrl);
                return $this->grantExternalVideoStream(
                    $courseChapterLecture,
                    $videoType,
                    $videoUrl,
                    $isFreePreview,
                );
            }

            if ($this->isDirectVideoFile($courseChapterLecture)) {
                return $this->grantExternalVideoStream(
                    $courseChapterLecture,
                    'video',
                    $courseChapterLecture->file,
                    $isFreePreview,
                );
            }

            return $this->buildUnavailableStreamResponse($courseChapterLecture);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError('Failed to access video stream', exception: $e);
        }
    }

    private function grantHlsStream(
        CourseChapterLecture $lecture,
        \App\Models\User $user,
        bool $isFreePreview,
    ): JsonResponse {
        $uuid = Str::uuid()->toString();

        Cache::put(
            "hls_token:{$uuid}",
            json_encode([
                'lecture_id' => $lecture->id,
                'user_id' => $user->id,
                'is_free_preview' => $isFreePreview,
                'created_at' => now()->timestamp,
            ]),
            self::TOKEN_EXPIRY_SECONDS,
        );

        return $this->ok(
            data: [
                'manifest_url' => url("/api/hls/{$uuid}"),
                'type' => 'hls',
                'file_type' => 'hls',
                'lecture_id' => $lecture->id,
                'lecture_title' => $lecture->title,
                'duration' => $lecture->duration,
                'expires_in_seconds' => self::TOKEN_EXPIRY_SECONDS,
                'is_free_preview' => $isFreePreview,
                'has_hls' => true,
            ],
            message: 'Video access granted',
        );
    }

    private function grantExternalVideoStream(
        CourseChapterLecture $lecture,
        string $type,
        null|string $videoUrl,
        bool $isFreePreview,
    ): JsonResponse {
        if ($videoUrl === null || $videoUrl === '') {
            return $this->unprocessableEntity('Video URL not available', [
                'type'       => $type,
                'file_type'  => $type,
                'has_hls'    => false,
                'lecture_id' => $lecture->id,
            ]);
        }

        $data = [
            'type'         => $type,
            'file_type'    => $type,
            'video_url'    => $videoUrl,
            'file_url'     => $videoUrl,
            'lecture_id'   => $lecture->id,
            'lecture_title'=> $lecture->title,
            'duration'     => $lecture->duration,
            'is_free_preview' => $isFreePreview,
            'has_hls'      => false,
        ];

        // For Bunny.net videos, extract video ID for frontend progress tracking
        if ($type === 'bunny') {
            $bunnyVideoId = $this->extractBunnyVideoId($videoUrl);
            $data['bunny_video_id'] = $bunnyVideoId;
            $data['progress_tracking'] = 'bunny_webhook'; // frontend hint
        }

        return $this->ok(data: $data, message: 'Video access granted');
    }

    private function buildUnavailableStreamResponse(CourseChapterLecture $lecture): JsonResponse
    {
        $message = match ($lecture->hls_status) {
            'pending' => 'Video is queued for processing',
            'processing' => 'Video is currently being processed',
            'failed' => 'Video encoding failed: ' . ($lecture->hls_error_message ?? 'Unknown error'),
            default => 'HLS video not available',
        };

        $responseData = [
            'hls_status' => $lecture->hls_status,
            'has_hls' => false,
            'file_type' => $lecture->file_type,
            'lecture_id' => $lecture->id,
        ];

        $errorMessage = $lecture->hls_error_message ?? '';
        $isHlsUnavailable =
            $lecture->hls_status === 'failed'
            && (
                str_contains($errorMessage, 'HLS encoding unavailable')
                || str_contains($errorMessage, 'FFmpeg not installed')
                || str_contains($errorMessage, 'proc_open')
            );

        if ($isHlsUnavailable && $this->isDirectVideoFile($lecture)) {
            $responseData['use_direct_video'] = true;
            $responseData['video_url'] = $lecture->file;
            $responseData['file_url'] = $lecture->file;
            $responseData['type'] = 'video';
            $message = 'Please use the direct video URL from the lecture data';
        }

        return $this->unprocessableEntity($message, $responseData);
    }

    private function isDirectVideoFile(CourseChapterLecture $lecture): bool
    {
        if ($lecture->type !== 'file') {
            return false;
        }

        $videoExtensions = ['mp4', 'avi', 'mov', 'webm', 'mkv', 'flv', 'wmv', 'm4v', '3gp', '3g2'];

        return in_array(strtolower($lecture->file_extension ?? ''), $videoExtensions, true);
    }

    /**
     * Detect the type of external video from its URL.
     * Returns: 'bunny' | 'yt'
     */
    private function detectExternalVideoType(?string $url): string
    {
        if ($url === null || $url === '') {
            return 'yt';
        }

        // Bunny.net domains
        $bunnyDomains = [
            'mediadelivery.net',
            'iframe.mediadelivery.net',
            'b-cdn.net',
            'bunnycdn.com',
        ];

        $parsedHost = parse_url($url, PHP_URL_HOST) ?? '';
        foreach ($bunnyDomains as $domain) {
            if (str_contains($parsedHost, $domain)) {
                return 'bunny';
            }
        }

        return 'yt';
    }

    /**
     * Extract Bunny.net video ID from embed URL.
     * e.g. https://iframe.mediadelivery.net/embed/423625/ed69b38c-94b7-4195-970b-6ded05193a44
     *      → 'ed69b38c-94b7-4195-970b-6ded05193a44'
     */
    private function extractBunnyVideoId(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        // Match UUID pattern at end of path
        if (preg_match('/\/embed\/\d+\/([a-f0-9\-]{36})/i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }


    /**
     * Serve HLS files (manifest, playlists, segments) with UUID validation
     */
    public function serve(string $uuid, null|string $path = null): Response|StreamedResponse|JsonResponse
    {
        try {
            // 1. Validate origin to prevent unauthorized domain access
            $originValidation = $this->validateOrigin();
            if ($originValidation !== null) {
                return $originValidation;
            }

            // 2. Default to master.m3u8 if no path specified
            $path ??= 'master.m3u8';

            // 3. Validate UUID token
            $tokenData = Cache::get("hls_token:{$uuid}");

            if ($tokenData === null) {
                return $this->forbidden('Access token expired or invalid');
            }

            // 4. Parse token data
            $data = json_decode($tokenData, true);
            $lectureId = $data['lecture_id'] ?? null;
            $userId = $data['user_id'] ?? null;
            $isFreePreview = $data['is_free_preview'] ?? false;

            if ($lectureId === null) {
                return $this->forbidden('Invalid token data');
            }

            // 5. Get lecture
            $lecture = CourseChapterLecture::find($lectureId);

            if ($lecture === null || !$lecture->hasHls()) {
                return $this->notFound('Video not found or not available');
            }

            // 5.5 Re-evaluate access to revoke immediately upon refund/expiry/ban.
            //     We use a short-lived cache (5 min) to avoid hitting the DB on
            //     every individual .ts segment — revocations still take effect well
            //     within the 30-minute token TTL.
            if (!$isFreePreview && $userId) {
                $user = \App\Models\User::find($userId);
                if (!$user) {
                    return $this->forbidden('Access revoked or subscription expired');
                }
                $accessCacheKey = "hls_access:{$userId}:{$lectureId}";
                $hasAccess = Cache::remember(
                    $accessCacheKey,
                    300, // 5 minutes
                    fn () => (int) app(\App\Services\ContentAccessService::class)->canAccessLecture($user, $lecture),
                );
                if (!(bool) $hasAccess) {
                    // Clear the cache immediately so the next request re-evaluates.
                    Cache::forget($accessCacheKey);
                    return $this->forbidden('Access revoked or subscription expired');
                }
            }

            // 6. Build file path (sanitize to prevent directory traversal)
            $sanitizedPath = str_replace(['..', '\\'], '', $path);
            $filePath = storage_path("app/public/hls/lectures/{$lecture->id}/{$sanitizedPath}");

            if (!file_exists($filePath)) {
                return $this->notFound('File not found');
            }

            // 7. Determine MIME type
            $mimeType = $this->getMimeType($sanitizedPath);

            // 8. For m3u8 files, rewrite paths to include UUID
            if (str_ends_with($sanitizedPath, '.m3u8')) {
                $content = file_get_contents($filePath);
                $content = $this->rewriteManifestPaths($content, $uuid);

                return response($content, 200, [
                    'Content-Type' => $mimeType,
                    'Content-Length' => (string) strlen($content),
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                    'Access-Control-Allow-Origin' => request()->header('Origin') ?? '*',
                    'Access-Control-Allow-Credentials' => 'true',
                    'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                    'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
                ]);
            }

            // 9. For other files (segments), stream directly
            return response()->stream(
                static function () use ($filePath): void {
                    $stream = fopen($filePath, 'rb');
                    fpassthru($stream);
                    fclose($stream);
                },
                200,
                [
                    'Content-Type' => $mimeType,
                    'Content-Length' => (string) filesize($filePath),
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                    'Access-Control-Allow-Origin' => request()->header('Origin') ?? '*',
                    'Access-Control-Allow-Credentials' => 'true',
                    'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                    'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
                ],
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->serverError('Failed to serve video file', exception: $e);
        }
    }

    /**
     * Rewrite manifest file paths to include UUID for proper resolution
     */
    private function rewriteManifestPaths(string $content, string $uuid): string
    {
        // Rewrite all non-comment, non-absolute lines to include UUID prefix
        $lines = explode("\n", $content);
        $rewritten = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Skip comments, empty lines, and lines starting with #
            if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
                $rewritten[] = $line;
                continue;
            }

            // Skip absolute URLs (http:// or https://)
            if (str_starts_with($trimmedLine, 'http://') || str_starts_with($trimmedLine, 'https://')) {
                $rewritten[] = $line;
                continue;
            }

            // Rewrite relative paths to include UUID
            // segment_000.ts -> /api/hls/{uuid}/segment_000.ts
            // 720p.m3u8 -> /api/hls/{uuid}/720p.m3u8
            $rewritten[] = url("/api/hls/{$uuid}/{$trimmedLine}");
        }

        return implode("\n", $rewritten);
    }

    /**
     * Get MIME type based on file extension
     */
    private function getMimeType(string $path): string
    {
        if (str_ends_with($path, '.m3u8')) {
            return 'application/vnd.apple.mpegurl';
        }

        if (str_ends_with($path, '.ts')) {
            return 'video/mp2t';
        }

        return 'application/octet-stream';
    }

    /**
     * Verify user is enrolled in the course containing this lecture
     */
    private function verifyUserEnrollment(int $userId, CourseChapterLecture $courseChapterLecture): bool
    {
        // Get course ID through chapter relationship
        $courseId = $courseChapterLecture->chapter?->course_id;

        if ($courseId === null) {
            return false;
        }

        // Check cache first
        $cacheKey = "enrollment:{$userId}:{$courseId}";
        $cachedResult = Cache::get($cacheKey);

        if ($cachedResult !== null) {
            return (bool) $cachedResult;
        }

        // Check if user has completed order for this course
        $isEnrolled = (bool) OrderCourse::whereHas('order', static function ($query) use ($userId): void {
            $query->where('user_id', $userId)->where('status', 'completed');
        })
            ->where('course_id', $courseId)
            ->exists();

        // Cache result
        Cache::put($cacheKey, $isEnrolled ? '1' : '0', self::ENROLLMENT_CACHE_SECONDS);

        return $isEnrolled;
    }

    /**
     * Validate request origin against allowed CORS origins
     * Returns generic error to prevent information disclosure
     */
    private function validateOrigin(): JsonResponse|null
    {
        $allowedOrigins = config('cors.allowed_origins', ['*']);

        // Allow all origins if wildcard is configured
        if (in_array('*', $allowedOrigins, true)) {
            return null;
        }

        // Get request origin from Origin or Referer header
        $origin = request()->header('Origin');
        $referer = request()->header('Referer');

        // Extract origin from referer if origin header is not present
        if ($origin === null && $referer !== null) {
            $parsedReferer = parse_url($referer);
            if ($parsedReferer !== false && isset($parsedReferer['scheme'], $parsedReferer['host'])) {
                $origin = $parsedReferer['scheme'] . '://' . $parsedReferer['host'];
                if (isset($parsedReferer['port']) && !in_array($parsedReferer['port'], [80, 443], true)) {
                    $origin .= ':' . $parsedReferer['port'];
                }
            }
        }

        // Block if no origin can be determined - don't tell them what's missing
        if ($origin === null) {
            return $this->forbidden('Access denied');
        }

        // Normalize origin (remove trailing slash)
        $origin = rtrim($origin, '/');

        // Check if origin is in allowed list
        $isAllowed = false;
        foreach ($allowedOrigins as $allowedOrigin) {
            $allowedOrigin = rtrim($allowedOrigin, '/');
            if (strcasecmp($origin, $allowedOrigin) === 0) {
                $isAllowed = true;
                break;
            }
        }

        // Return generic error - don't reveal it's an origin issue
        if (!$isAllowed) {
            return $this->forbidden('Access denied');
        }

        return null;
    }
}
