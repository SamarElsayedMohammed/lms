<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Webinar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminWebinarController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * List webinars managed by admin/instructor
     * GET /api/admin/webinars
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $isInstructor = Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));

        $query = Webinar::with([
            'instructor:id,name',
            'course:id,title'
        ]);
        if ($isInstructor) {
            $query->where('instructor_id', Auth::id());
        }

        // Filters
        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $perPage = min((int) $request->input('per_page', 15), 50);
        $webinars = $query->latest()->paginate($perPage);

        // Stats
        $statsQuery = Webinar::query();
        if ($isInstructor) {
            $statsQuery->where('instructor_id', Auth::id());
        }

        $stats = $statsQuery->selectRaw("
            COUNT(*) as total,
            COUNT(CASE WHEN status IN ('scheduled','live') THEN 1 END) as published,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled
        ")->first();

        $data        = $webinars->toArray();
        $data['stats'] = [
            'total'     => (int) $stats->total,
            'published' => (int) $stats->published,
            'completed' => (int) $stats->completed,
            'cancelled' => (int) $stats->cancelled,
        ];

        return $this->jsonSuccess('Webinars retrieved successfully', $data);
    }

    /**
     * Show a single webinar with full details
     * GET /api/admin/webinars/{slug}
     */
    public function show(Webinar $webinar): JsonResponse
    {
        $this->ensureAdmin();

        $webinar->load([
            'instructor:id,name,email',
            'course:id,title',
            'registrations',
        ]);

        // Instructor ownership check
        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            && $webinar->instructor_id !== Auth::id()) {
            return $this->jsonError('Unauthorized', 403);
        }

        $data = $webinar->toArray();
        $data['registrations_count'] = $webinar->registrations->count();
        $data['spots_left']          = $webinar->spots_left;
        $data['is_full']             = $webinar->is_full;

        return $this->jsonSuccess('Webinar retrieved successfully', $data);
    }

    /**
     * Create a new webinar
     * POST /api/admin/webinars
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'title'         => 'required|string|max:255',
            'course_id'     => 'nullable|exists:courses,id',
            'description'   => 'nullable|string',
            'start_at'      => 'required|date|after:now',
            'duration'      => 'required|integer|min:5',
            'is_free'       => 'required|boolean',
            'price'         => 'required_if:is_free,false|numeric|min:0',
            'provider'      => 'required|in:zoom,jitsi,google_meet,custom',
            'join_url'      => 'nullable|url',
            'features'      => 'nullable|array',
            'max_attendees' => 'nullable|integer|min:0',
            'tags'          => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            $data                 = $validator->validated();
            $data['instructor_id'] = Auth::id();
            $data['slug']         = Str::slug($request->title) . '-' . Str::random(5);

            if ($request->is_free) {
                $data['price'] = 0; // Price Constraint
            }

            // The JSON Config Hack Migration
            if (isset($data['features']) && is_array($data['features'])) {
                foreach ($data['features'] as $key => $feature) {
                    if (is_string($feature) && str_starts_with($feature, '__skillso_webinar_config_v1__:')) {
                        $jsonConfig = substr($feature, strlen('__skillso_webinar_config_v1__:'));
                        $data['config'] = json_decode($jsonConfig, true);
                        unset($data['features'][$key]);
                    }
                }
                // Re-index array if needed
                $data['features'] = array_values($data['features']);
            }

            if ($request->provider === 'jitsi' && empty($request->join_url)) {
                $data['join_url'] = 'https://meet.jit.si/' . $data['slug'];
            } elseif ($request->provider === 'zoom') {
                try {
                    $zoomService  = app(\App\Services\ZoomService::class);
                    $zoomResponse = $zoomService->createMeeting(
                        $request->title,
                        $request->start_at,
                        (int) $request->duration
                    );
                    if ($zoomResponse['success']) {
                        $data['join_url']          = $zoomResponse['data']['join_url'];
                        $data['meeting_id']        = (string) $zoomResponse['data']['meeting_id'];
                        $data['meeting_password']  = $zoomResponse['data']['password'];
                    }
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Zoom Service Error: ' . $e->getMessage());
                }
            } elseif ($request->provider === 'google_meet') {
                try {
                    $googleService  = app(\App\Services\GoogleMeetService::class);
                    $googleResponse = $googleService->createMeeting(
                        $request->title,
                        $request->start_at,
                        (int) $request->duration
                    );
                    if ($googleResponse['success']) {
                        $data['join_url']   = $googleResponse['data']['join_url'];
                        $data['meeting_id'] = (string) $googleResponse['data']['meeting_id'];
                    }
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Google Meet Service Error: ' . $e->getMessage());
                }
            }

            $webinar = Webinar::create($data);

            return $this->jsonSuccess('Webinar created successfully', $webinar->fresh('instructor'), 201);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to create webinar: ' . $e->getMessage());
        }
    }

    /**
     * Update webinar details
     * PUT /api/admin/webinars/{slug}
     */
    public function update(Request $request, Webinar $webinar): JsonResponse
    {
        $this->ensureAdmin();

        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            && $webinar->instructor_id !== Auth::id()) {
            return $this->jsonError('Unauthorized', 403);
        }

        $validator = Validator::make($request->all(), [
            'title'         => 'sometimes|required|string|max:255',
            'course_id'     => 'nullable|exists:courses,id',
            'description'   => 'nullable|string',
            'start_at'      => 'sometimes|required|date',
            'duration'      => 'sometimes|required|integer|min:5',
            'status'        => 'sometimes|required|in:scheduled,live,completed,cancelled',
            'recording_url' => 'nullable|url',
            'join_url'      => 'nullable|url',
            'features'      => 'nullable|array',
            'max_attendees' => 'nullable|integer|min:0',
            'tags'          => 'nullable|string',
            'is_free'       => 'sometimes|boolean',
            'price'         => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            $data = $validator->validated();

            if (isset($data['is_free']) && $data['is_free']) {
                $data['price'] = 0; // Price Constraint
            }

            // The JSON Config Hack Migration
            if (isset($data['features']) && is_array($data['features'])) {
                foreach ($data['features'] as $key => $feature) {
                    if (is_string($feature) && str_starts_with($feature, '__skillso_webinar_config_v1__:')) {
                        $jsonConfig = substr($feature, strlen('__skillso_webinar_config_v1__:'));
                        $data['config'] = json_decode($jsonConfig, true);
                        unset($data['features'][$key]);
                    }
                }
                $data['features'] = array_values($data['features']);
            }

            $webinar->update($data);
            return $this->jsonSuccess('Webinar updated successfully', $webinar->fresh('instructor'));
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to update webinar: ' . $e->getMessage());
        }
    }

    /**
     * Delete webinar (Soft Delete)
     * DELETE /api/admin/webinars/{slug}
     */
    public function destroy(Webinar $webinar): JsonResponse
    {
        $this->ensureAdmin();

        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            && $webinar->instructor_id !== Auth::id()) {
            return $this->jsonError('Unauthorized', 403);
        }

        $webinar->delete();

        return $this->jsonSuccess('Webinar deleted successfully');
    }

    /**
     * List registrants for a specific webinar
     * GET /api/admin/webinars/{slug}/registrants
     */
    public function registrants(Request $request, Webinar $webinar): JsonResponse
    {
        $this->ensureAdmin();

        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            && $webinar->instructor_id !== Auth::id()) {
            return $this->jsonError('Unauthorized', 403);
        }

        $registrations = \App\Models\WebinarRegistration::with('user:id,name,email,mobile')
            ->where('webinar_id', $webinar->id)
            ->latest()
            ->get()
            ->map(fn ($reg) => [
                'id'             => $reg->id,
                'user_id'        => $reg->user_id,
                'name'           => $reg->user->name  ?? 'N/A',
                'email'          => $reg->user->email ?? 'N/A',
                'phone'          => $reg->user->mobile ?? 'N/A',
                'payment_status' => $reg->payment_status,
                'paid_amount'    => $reg->paid_amount,
                'registered_at'  => $reg->created_at->toDateTimeString(),
            ]);

        return $this->jsonSuccess('Registrants retrieved successfully', [
            'webinar_id'        => $webinar->id,
            'webinar_title'     => $webinar->title,
            'total_registrants' => $registrations->count(),
            'registrants'       => $registrations,
        ]);
    }

    /**
     * Export webinar registrants as CSV
     * GET /api/admin/webinars/{slug}/registrants/export
     */
    public function exportRegistrants(Webinar $webinar): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $this->ensureAdmin();

        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            && $webinar->instructor_id !== Auth::id()) {
            return $this->jsonError('Unauthorized', 403);
        }

        $registrations = \App\Models\WebinarRegistration::with('user:id,name,email,mobile')
            ->where('webinar_id', $webinar->id)
            ->oldest()
            ->get();

        $filename = 'webinar-registrants-' . $webinar->id . '-' . now()->format('Ymd-His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        $callback = function () use ($registrations, $webinar) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['Webinar', $webinar->title]);
            fputcsv($handle, ['Date', $webinar->start_at?->format('Y-m-d H:i')]);
            fputcsv($handle, ['Total Registrants', $registrations->count()]);
            fputcsv($handle, []); 

            fputcsv($handle, [
                '#',
                'Name',
                'Email',
                'Phone',
                'Payment Status',
                'Paid Amount',
                'Registered At',
            ]);

            foreach ($registrations as $index => $reg) {
                fputcsv($handle, [
                    $index + 1,
                    $reg->user->name    ?? 'N/A',
                    $reg->user->email   ?? 'N/A',
                    $reg->user->mobile  ?? 'N/A',
                    $reg->payment_status,
                    $reg->paid_amount   ?? '0.00',
                    $reg->created_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
