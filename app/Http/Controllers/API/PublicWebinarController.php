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
            $validated = $request->validate([
                'course_id' => ['nullable', 'integer', 'min:1'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
            $user = Auth::guard('sanctum')->user();

            Webinar::syncPublishedLifecycleStatuses();

            $query = Webinar::with(['instructor:id,name', 'course:id,title'])
                ->withCount([
                    'registrations as registrations_count' => static fn ($registrationQuery) => $registrationQuery
                        ->consumesCapacity(),
                ])
                ->where('is_published', true)
                ->whereIn('status', ['scheduled', 'live']);

            if ($user) {
                $query->withExists(['registrations as is_registered' => function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->whereIn('payment_status', ['paid', 'free']);
                }]);
            }

            if (!empty($validated['course_id'])) {
                $query->where('course_id', $validated['course_id']);
            }

            $perPage = (int) ($validated['per_page'] ?? 15);
            $webinars = $query->orderBy('start_at', 'asc')->paginate($perPage);

            $webinars->getCollection()->transform(function ($webinar) use ($user) {
                if (isset($webinar->is_registered_exists)) {
                    $webinar->is_registered = (bool) $webinar->is_registered_exists;
                }
                $registrationCount = (int) ($webinar->registrations_count ?? 0);
                $maxAttendees = (int) ($webinar->max_attendees ?? 0);
                $webinar->registered_count = $registrationCount;
                $webinar->spots_left = $maxAttendees > 0
                    ? max(0, $maxAttendees - $registrationCount)
                    : null;
                $webinar->is_full = $maxAttendees > 0 && $registrationCount >= $maxAttendees;
                return $this->accessService->sanitizeWebinarForResponse($webinar, $user);
            });

            return ApiResponseService::successResponse('Webinars retrieved successfully', $webinars);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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

            $webinar->syncLifecycleStatus();

            // Access check: unpublished webinars require administrative/instructor preview privileges
            if (!$this->accessService->canViewWebinar($webinar, $user)) {
                return ApiResponseService::errorResponse('Webinar not found', [], 404);
            }

            $webinar->load(['instructor:id,name', 'course:id,title']);

            $isEntitled = $this->accessService->isUserEntitled($webinar, $user);
            $registration = $this->accessService->getRegistration($webinar, $user);
            $isRegistered = $registration?->isConfirmed() ?? false;

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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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

            $webinar->syncLifecycleStatus();

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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to join webinar: ' . $e->getMessage());
        }
    }

    /**
     * Recording URL for entitled attendees after the event ends.
     * GET /api/webinars/{slug}/recording
     */
    public function recording(Webinar $webinar)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            $webinar->syncLifecycleStatus();

            $check = $this->accessService->canViewRecording($webinar, $user);
            if (!$check['allowed']) {
                return ApiResponseService::errorResponse(
                    $check['reason'],
                    ['error_code' => $check['error_code']],
                    $check['code']
                );
            }

            return ApiResponseService::successResponse('Recording retrieved', [
                'recording_url' => $check['recording_url'],
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve recording: ' . $e->getMessage());
        }
    }
}
