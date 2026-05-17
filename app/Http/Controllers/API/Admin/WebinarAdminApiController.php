<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WebinarAdminApiController extends AdminCrudApiController
{
    /**
     * List webinars managed by admin/instructor
     */
    public function index(Request $request)
    {
        $this->ensureAdmin();
        // If instructor, filter by instructor_id
        $query = Webinar::with('instructor:id,name');
        
        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
            $query->where('instructor_id', Auth::id());
        }

        $perPage = min((int) $request->input('per_page', 15), 50);
        $webinars = $query->latest()->paginate($perPage);

        return ApiResponseService::successResponse('Webinars retrieved successfully', $webinars);
    }

    /**
     * Create a new webinar
     */
    public function store(Request $request)
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_at' => 'required|date|after:now',
            'duration' => 'required|integer|min:5',
            'is_free' => 'required|boolean',
            'price' => 'required_if:is_free,false|numeric|min:0',
            'provider' => 'required|in:zoom,jitsi,google_meet,custom',
            'join_url' => 'nullable|url',
            'features' => 'nullable|array',
            'max_attendees' => 'nullable|integer|min:0',
            'tags' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $request->all();
            $data['instructor_id'] = Auth::id();
            $data['slug'] = Str::slug($request->title) . '-' . Str::random(5);
            
            if ($request->provider === 'jitsi' && empty($request->join_url)) {
                $data['join_url'] = "https://meet.jit.si/" . $data['slug'];
            } elseif ($request->provider === 'zoom') {
                try {
                    $zoomService = app(\App\Services\ZoomService::class);
                    $zoomResponse = $zoomService->createMeeting(
                        $request->title,
                        $request->start_at,
                        (int) $request->duration
                    );

                    if ($zoomResponse['success']) {
                        $data['join_url'] = $zoomResponse['data']['join_url'];
                        $data['meeting_id'] = (string) $zoomResponse['data']['meeting_id'];
                        $data['meeting_password'] = $zoomResponse['data']['password'];
                    } else {
                        \Illuminate\Support\Facades\Log::warning('Failed to auto-create Zoom meeting: ' . $zoomResponse['message']);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Zoom Service Error: ' . $e->getMessage());
                }
            } elseif ($request->provider === 'google_meet') {
                try {
                    $googleService = app(\App\Services\GoogleMeetService::class);
                    $googleResponse = $googleService->createMeeting(
                        $request->title,
                        $request->start_at,
                        (int) $request->duration
                    );

                    if ($googleResponse['success']) {
                        $data['join_url'] = $googleResponse['data']['join_url'];
                        $data['meeting_id'] = (string) $googleResponse['data']['meeting_id'];
                    } else {
                        \Illuminate\Support\Facades\Log::warning('Failed to auto-create Google Meet: ' . $googleResponse['message']);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Google Meet Service Error: ' . $e->getMessage());
                }
            }

            $webinar = Webinar::create($data);

            return ApiResponseService::successResponse('Webinar created successfully', $webinar);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to create webinar: ' . $e->getMessage());
        }
    }

    /**
     * Update webinar details
     */
    public function update(Request $request, $id)
    {
        $this->ensureAdmin();
        $webinar = Webinar::findOrFail($id);

        // Security check for instructors
        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR')) && $webinar->instructor_id !== Auth::id()) {
            return ApiResponseService::errorResponse('Unauthorized', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'start_at' => 'sometimes|required|date',
            'duration' => 'sometimes|required|integer|min:5',
            'status' => 'sometimes|required|in:scheduled,live,completed,cancelled',
            'recording_url' => 'nullable|url',
            'features' => 'nullable|array',
            'max_attendees' => 'nullable|integer|min:0',
            'tags' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $webinar->update($request->all());
            return ApiResponseService::successResponse('Webinar updated successfully', $webinar);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to update webinar: ' . $e->getMessage());
        }
    }

    /**
     * Delete webinar
     */
    public function destroy($id)
    {
        $this->ensureAdmin();
        $webinar = Webinar::findOrFail($id);

        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR')) && $webinar->instructor_id !== Auth::id()) {
            return ApiResponseService::errorResponse('Unauthorized', [], 403);
        }

        $webinar->delete();
        return ApiResponseService::successResponse('Webinar deleted successfully');
    }

    /**
     * List registrants for a specific webinar
     * GET /api/admin/webinars/{id}/registrants
     */
    public function registrants(Request $request, $id)
    {
        $this->ensureAdmin();
        $webinar = Webinar::findOrFail($id);

        // Security check for instructors
        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR')) && $webinar->instructor_id !== Auth::id()) {
            return ApiResponseService::errorResponse('Unauthorized', [], 403);
        }

        $registrations = \App\Models\WebinarRegistration::with('user:id,name,email,mobile')
            ->where('webinar_id', $id)
            ->latest()
            ->get()
            ->map(function ($reg) {
                return [
                    'id' => $reg->id,
                    'user_id' => $reg->user_id,
                    'name' => $reg->user->name ?? 'N/A',
                    'email' => $reg->user->email ?? 'N/A',
                    'phone' => $reg->user->mobile ?? 'N/A',
                    'payment_status' => $reg->payment_status,
                    'paid_amount' => $reg->paid_amount,
                    'registered_at' => $reg->created_at->toDateTimeString(),
                ];
            });

        return ApiResponseService::successResponse('Registrants retrieved successfully', [
            'webinar_title' => $webinar->title,
            'total_registrants' => $registrations->count(),
            'registrants' => $registrations,
        ]);
    }
}
