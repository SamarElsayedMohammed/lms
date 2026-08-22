<?php

namespace App\Http\Controllers\API\User;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use App\Services\WebinarAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserWebinarApiController extends Controller
{
    protected WebinarAccessService $accessService;

    public function __construct(WebinarAccessService $accessService)
    {
        $this->accessService = $accessService;
    }

    /**
     * Get authenticated user's registered webinars (My Webinars / Live Sessions)
     * GET /api/user/my-webinars
     */
    public function myWebinars(Request $request)
    {
        try {
            $validated = $request->validate([
                'status' => ['nullable', 'in:all,upcoming,completed,past,cancelled'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthorized.', [], 401);
            }

            Webinar::syncPublishedLifecycleStatuses();

            $query = WebinarRegistration::with(['webinar.instructor:id,name', 'webinar.course:id,title'])
                ->where('user_id', $user->id)
                ->whereIn('payment_status', ['paid', 'free'])
                ->whereHas('webinar');

            $filter = $validated['status'] ?? 'all';
            if ($filter === 'upcoming') {
                $query->whereHas('webinar', function ($q) {
                    $q->where(function ($statusQuery) {
                        $statusQuery->where('status', 'live')
                            ->orWhere(function ($scheduledQuery) {
                                $scheduledQuery->where('status', 'scheduled')
                                    ->where('start_at', '>=', now());
                            });
                    });
                });
            } elseif ($filter === 'completed' || $filter === 'past') {
                $query->whereHas('webinar', function ($q) {
                    $q->where('status', 'completed');
                });
            } elseif ($filter === 'cancelled') {
                $query->whereHas('webinar', function ($q) {
                    $q->where('status', 'cancelled');
                });
            }

            $perPage = (int) ($validated['per_page'] ?? 15);
            $registrations = $query->orderBy('created_at', 'desc')->paginate($perPage);

            $registrations->getCollection()->transform(function ($registration) use ($user) {
                $webinar = $registration->webinar;
                if (!$webinar) {
                    return null;
                }

                $webinar->syncLifecycleStatus();

                $isEntitled = $this->accessService->isUserEntitled($webinar, $user);
                $sanitized = $this->accessService->sanitizeWebinarForResponse($webinar, $user);

                $data = $sanitized->toArray();
                $data['is_registered'] = true;
                $data['is_entitled'] = $isEntitled;
                $data['registration_id'] = $registration->id;
                $data['payment_status'] = $registration->payment_status;
                $data['paid_amount'] = $registration->paid_amount;
                $data['registered_at'] = $registration->created_at ? $registration->created_at->toIso8601String() : null;
                $data['attended'] = (bool) $registration->attended;
                $data['attended_at'] = $registration->attended_at ? $registration->attended_at->toIso8601String() : null;

                // If entitled and webinar is completed with recording, expose recording_url explicitly
                if ($isEntitled && $webinar->status === 'completed' && !empty($webinar->recording_url)) {
                    $data['recording_url'] = $webinar->recording_url;
                }

                return $data;
            });

            // Filter out any nulls
            $filteredCollection = $registrations->getCollection()->filter()->values();
            $registrations->setCollection($filteredCollection);

            return ApiResponseService::successResponse('My registered webinars retrieved successfully', [
                'items' => $filteredCollection,
                'pagination' => [
                    'current_page' => $registrations->currentPage(),
                    'last_page' => $registrations->lastPage(),
                    'per_page' => $registrations->perPage(),
                    'total' => $registrations->total(),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve registered webinars: ' . $e->getMessage());
        }
    }
}
