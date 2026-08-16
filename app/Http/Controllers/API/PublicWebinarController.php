<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use App\Services\WebinarAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PublicWebinarController extends Controller
{
    protected WebinarAccessService $accessService;

    public function __construct(WebinarAccessService $accessService)
    {
        $this->accessService = $accessService;
    }

    /**
     * List all scheduled/live webinars (Public)
     * GET /api/webinars
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('sanctum')->user();

            $query = Webinar::with(['instructor:id,name', 'course:id,title'])
                ->where('is_published', true)
                ->whereIn('status', ['scheduled', 'live'])
                ->where('start_at', '>=', now()->subHours(2));

            if ($user) {
                $query->withExists(['registrations as is_registered' => function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->where(function ($sub) {
                          $sub->whereIn('payment_status', ['paid', 'free'])
                              ->orWhere(function ($p) {
                                  $p->where('payment_status', 'pending')
                                    ->where(function ($e) {
                                        $e->whereNull('expires_at')->orWhere('expires_at', '>', now());
                                    });
                              });
                      });
                }]);
            }

            if ($request->has('course_id')) {
                $query->where('course_id', $request->input('course_id'));
            }

            $perPage = min((int) $request->input('per_page', 15), 50);
            $webinars = $query->orderBy('start_at', 'asc')->paginate($perPage);

            $webinars->getCollection()->transform(function ($webinar) use ($user) {
                if (isset($webinar->is_registered_exists)) {
                    $webinar->is_registered = (bool) $webinar->is_registered_exists;
                }
                return $this->accessService->sanitizeWebinarForResponse($webinar, $user);
            });

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
            $user = Auth::guard('sanctum')->user();

            // Access check: unpublished webinars require administrative/instructor preview privileges
            if (!$this->accessService->canViewWebinar($webinar, $user)) {
                return ApiResponseService::errorResponse('Webinar not found', [], 404);
            }

            $webinar->load(['instructor:id,name', 'course:id,title']);

            $isEntitled = $this->accessService->isUserEntitled($webinar, $user);
            $registration = $this->accessService->getRegistration($webinar, $user);
            $isRegistered = $registration && ($registration->isConfirmed() || ($registration->isPending() && !$registration->isExpired()));

            // Sanitize webinar model to eliminate any sensitive credential leaks
            $sanitizedWebinar = $this->accessService->sanitizeWebinarForResponse($webinar, $user);
            $sanitizedWebinar->append(['spots_left', 'is_full']);

            return ApiResponseService::successResponse('Webinar details retrieved', [
                'webinar' => $sanitizedWebinar,
                'is_registered' => (bool) $isRegistered,
                'is_entitled' => (bool) $isEntitled,
                'payment_status' => $registration ? $registration->payment_status : null,
                'registered_count' => $webinar->activeRegistrationsCount(),
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve webinar: ' . $e->getMessage());
        }
    }

    /**
     * Join a webinar (authoritative join URL gateway)
     * GET /api/webinars/{slug}/join
     */
    public function join(Webinar $webinar)
    {
        try {
            $user = Auth::guard('sanctum')->user();

            $joinCheck = $this->accessService->canJoinLive($webinar, $user);
            if (!$joinCheck['allowed']) {
                return ApiResponseService::errorResponse(
                    $joinCheck['reason'],
                    ['error_code' => $joinCheck['error_code']],
                    $joinCheck['code']
                );
            }

            // Record attendance check-in on the user's registration
            if ($user) {
                WebinarRegistration::where('user_id', $user->id)
                    ->where('webinar_id', $webinar->id)
                    ->whereNull('attended_at')
                    ->update([
                        'attended_at' => now(),
                        'attended' => true,
                    ]);
            }

            return ApiResponseService::successResponse('Join link generated', $joinCheck['data']);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to join webinar: ' . $e->getMessage());
        }
    }
}
