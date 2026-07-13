<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PublicWebinarController extends Controller
{
    /**
     * List all scheduled/live webinars (Public)
     * GET /api/webinars
     */
    public function index(Request $request)
    {
        try {
            $query = Webinar::with(['instructor:id,name,profile', 'course:id,title'])
                ->where('is_published', true)
                ->whereIn('status', ['scheduled', 'live'])
                ->where('start_at', '>=', now()->subHours(2));

            if ($request->has('course_id')) {
                $query->where('course_id', $request->input('course_id'));
            }

            $perPage = min((int) $request->input('per_page', 15), 50);
            $webinars = $query->orderBy('start_at', 'asc')->paginate($perPage);

            return ApiResponseService::successResponse('Webinars retrieved successfully', $webinars);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve webinars: ' . $e->getMessage());
        }
    }

    /**
     * Get webinar details (Public)
     * GET /api/webinars/{slug}
     */
    public function show(Webinar $webinar)
    {
        try {
            // Check if published
            if (!$webinar->is_published) {
                return ApiResponseService::errorResponse('Webinar not found', [], 404);
            }

            $webinar->load(['instructor:id,name,profile']);

            $user = Auth::guard('sanctum')->user();
            $is_registered = $user ? WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->exists() : false;

            // PII Protection: do not load registrations relationship
            // $webinar->load('registrations'); // REMOVED to protect PII

            // Hide sensitive links if not registered or not completed
            if (!$is_registered) {
                $webinar->makeHidden(['join_url', 'meeting_password']);
            }
            if (!$is_registered || $webinar->status !== 'completed') {
                $webinar->makeHidden(['recording_url']);
            }

            $webinar->append(['spots_left', 'is_full']);

            return ApiResponseService::successResponse('Webinar details retrieved', [
                'webinar' => $webinar,
                'is_registered' => $is_registered,
                'registered_count' => WebinarRegistration::where('webinar_id', $webinar->id)->count(),
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve webinar: ' . $e->getMessage());
        }
    }

    /**
     * Join a webinar (get join URL)
     * GET /api/webinars/{slug}/join
     */
    public function join(Webinar $webinar)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthorized.', [], 401);
            }

            $registration = WebinarRegistration::where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)
                ->first();

            if (!$registration && !$webinar->is_free) {
                return ApiResponseService::errorResponse('You must register for this webinar first.');
            }

            if ($webinar->status === 'scheduled' && $webinar->start_at->gt(now()->addMinutes(15))) {
                return ApiResponseService::errorResponse('The webinar has not started yet. You can join 15 minutes before the start time.');
            }

            $join_url = $webinar->join_url;
            if ($webinar->provider === 'jitsi' && empty($join_url)) {
                $join_url = "https://meet.jit.si/" . $webinar->slug;
            }

            return ApiResponseService::successResponse('Join link generated', [
                'join_url' => $join_url,
                'meeting_id' => $webinar->meeting_id,
                'meeting_password' => $webinar->meeting_password
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to join webinar: ' . $e->getMessage());
        }
    }
}
