<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Events\WebinarCancelled;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\WebinarConfigSanitizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminWebinarController extends AdminCrudApiController
{
    use Concerns\AuthorizesWebinarManagement;

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
        $this->ensureWebinarManager();

        $isInstructor = $this->isInstructorScoped();

        $query = Webinar::with([
            'instructor:id,name',
            'course:id,title'
        ]);
        if ($isInstructor) {
            $query->where('instructor_id', Auth::id());
        }

        // Filters
        if ($search = $request->input('search', $request->input('q'))) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%"));
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $perPage = min((int) $request->input('per_page', $request->input('limit', 15)), 50);
        $webinars = $query->latest()->paginate($perPage);

        // Stats
        $statsQuery = Webinar::query();
        if ($isInstructor) {
            $statsQuery->where('instructor_id', Auth::id());
        }

        $stats = $statsQuery->selectRaw("
            COUNT(*) as total,
            COUNT(CASE WHEN is_published = 1 THEN 1 END) as published,
            COUNT(CASE WHEN status = 'scheduled' THEN 1 END) as scheduled,
            COUNT(CASE WHEN status = 'live' THEN 1 END) as live,
            COUNT(CASE WHEN status IN ('scheduled','live') THEN 1 END) as active,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled
        ")->first();

        $data        = $webinars->toArray();
        $data['stats'] = [
            'total'     => (int) $stats->total,
            'published' => (int) $stats->published,
            'scheduled' => (int) $stats->scheduled,
            'live'      => (int) $stats->live,
            'active'    => (int) $stats->active,
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
        $this->ensureWebinarManager();

        $webinar->load([
            'instructor:id,name,email',
            'course:id,title',
            'registrations',
        ]);

        $denied = $this->ensureCanManageWebinar($webinar);
        if ($denied) {
            return $denied;
        }

        $data = $webinar->toArray();
        $data['registrations_count'] = $webinar->activeRegistrationsCount();
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
        $this->ensureWebinarManager();
        $this->prepareWebinarPayload($request);

        $validator = Validator::make($request->all(), [
            'title'         => 'required|string|max:255',
            'slug'          => 'nullable|string|max:100',
            'instructor_id' => 'nullable|exists:users,id',
            'course_id'     => 'nullable|exists:courses,id',
            'description'   => 'nullable|string',
            'start_at'      => 'required|date|after:now',
            'duration'      => 'required|integer|min:5',
            'is_free'       => 'required|boolean',
            'price'         => 'required_if:is_free,false|numeric|min:0',
            'provider'      => 'required|in:zoom,jitsi,google_meet,custom',
            'join_url'      => 'nullable|url',
            'recording_url' => 'nullable|url',
            'image'         => 'nullable|string',
            'features'      => 'nullable|array',
            'config'        => 'nullable|array',
            'max_attendees' => 'nullable|integer|min:0',
            'tags'          => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            $data = $validator->validated();
            if (isset($data['config']) && is_array($data['config'])) {
                $configErrors = app(WebinarConfigSanitizer::class)->validateAdminConfig($data['config']);
                if ($configErrors !== []) {
                    return $this->jsonError(reset($configErrors), 422);
                }
            }

            // Instructor assignment authorization
            $user = Auth::user();
            $isSuperOrStaff = $user->hasRole('Super Admin') || $user->hasRole('Supervisor') || $user->hasRole('Staff');
            if ($isSuperOrStaff && !empty($data['instructor_id'])) {
                $data['instructor_id'] = (int) $data['instructor_id'];
            } else {
                $data['instructor_id'] = (int) $user->id;
            }

            // Slug assignment & persistence
            if (!empty($data['slug'])) {
                $candidateSlug = Str::slug($data['slug']);
                if (Webinar::where('slug', $candidateSlug)->exists()) {
                    $data['slug'] = $candidateSlug . '-' . Str::random(4);
                } else {
                    $data['slug'] = $candidateSlug;
                }
            } else {
                $data['slug'] = Str::slug($request->title) . '-' . Str::random(5);
            }

            if ($request->is_free) {
                $data['price'] = 0; // Price Constraint
            }

            $this->extractLegacyConfigFeature($data);

            if ($request->provider === 'jitsi' && empty($request->join_url)) {
                // A slug is public and predictable. The Jitsi room identifier must not be.
                $data['join_url'] = 'https://meet.jit.si/skillso-' . Str::lower(Str::random(32));
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

            $webinar = Webinar::create(array_merge($data, [
                'status' => 'scheduled',
                'is_published' => false,
            ]));

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
        $this->ensureWebinarManager();
        $this->prepareWebinarPayload($request);

        $denied = $this->ensureCanManageWebinar($webinar);
        if ($denied) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'title'         => 'sometimes|required|string|max:255',
            'slug'          => 'nullable|string|max:100|unique:webinars,slug,' . $webinar->id,
            'instructor_id' => 'nullable|exists:users,id',
            'course_id'     => 'nullable|exists:courses,id',
            'description'   => 'nullable|string',
            'start_at'      => 'sometimes|required|date',
            'duration'      => 'sometimes|required|integer|min:5',
            'status'        => 'sometimes|required|in:scheduled,live,completed,cancelled',
            'recording_url' => 'nullable|url',
            'join_url'      => 'nullable|url',
            'image'         => 'nullable|string',
            'features'      => 'nullable|array',
            'config'        => 'nullable|array',
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
            if (isset($data['config']) && is_array($data['config'])) {
                $configErrors = app(WebinarConfigSanitizer::class)->validateAdminConfig($data['config']);
                if ($configErrors !== []) {
                    return $this->jsonError(reset($configErrors), 422);
                }
            }

            // Instructor reassignment authorization
            $user = Auth::user();
            $isSuperOrStaff = $user->hasRole('Super Admin') || $user->hasRole('Supervisor') || $user->hasRole('Staff');
            if (isset($data['instructor_id'])) {
                if (!$isSuperOrStaff) {
                    unset($data['instructor_id']);
                } else {
                    $data['instructor_id'] = (int) $data['instructor_id'];
                }
            }

            if (isset($data['slug']) && !empty($data['slug'])) {
                $data['slug'] = Str::slug($data['slug']);
            }

            if (isset($data['is_free']) && $data['is_free']) {
                $data['price'] = 0; // Price Constraint
            }

            $this->extractLegacyConfigFeature($data);

            $oldStatus = $webinar->status;
            $webinar->update($data);

            // Dispatch cancellation if status transitioned to cancelled
            if (isset($data['status']) && $data['status'] === 'cancelled' && $oldStatus !== 'cancelled' && class_exists(WebinarCancelled::class)) {
                event(new WebinarCancelled($webinar));
            }

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
        $this->ensureWebinarManager();

        $denied = $this->ensureCanManageWebinar($webinar);
        if ($denied) {
            return $denied;
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
        $this->ensureWebinarManager();

        $denied = $this->ensureCanManageWebinar($webinar);
        if ($denied) {
            return $denied;
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? $validated['limit'] ?? 20);
        $query = WebinarRegistration::with('user:id,name,email,mobile')
            ->where('webinar_id', $webinar->id);

        if (!empty($validated['q'])) {
            $search = trim($validated['q']);
            $query->where(function ($registrationQuery) use ($search) {
                $registrationQuery->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                })->orWhere('form_responses->name', 'like', "%{$search}%")
                    ->orWhere('form_responses->email', 'like', "%{$search}%")
                    ->orWhere('form_responses->phone', 'like', "%{$search}%")
                    ->orWhere('form_responses->whatsapp', 'like', "%{$search}%");
            });
        }

        $paginator = $query->latest()->paginate($perPage);
        $registrations = $paginator->getCollection()
            ->map(fn ($reg) => [
                'id'             => $reg->id,
                'user_id'        => $reg->user_id,
                'name'           => $reg->user?->name  ?? ($reg->form_responses['name'] ?? 'N/A'),
                'email'          => $reg->user?->email ?? ($reg->form_responses['email'] ?? 'N/A'),
                'phone'          => $reg->user?->mobile ?? ($reg->form_responses['whatsapp'] ?? ($reg->form_responses['phone'] ?? 'N/A')),
                'payment_status' => $reg->payment_status,
                'payment_method' => $reg->payment_status === 'paid' ? 'wallet' : 'free',
                'paid_amount'    => $reg->paid_amount,
                'attended'       => (bool) $reg->attended,
                'attended_at'    => $reg->attended_at ? $reg->attended_at->toDateTimeString() : null,
                'registered_at'  => $reg->created_at ? $reg->created_at->toDateTimeString() : null,
                'form_responses' => $reg->form_responses ?? [],
                'utm_source'     => $reg->utm_source,
            ]);

        return $this->jsonSuccess('Registrants retrieved successfully', [
            'webinar_id'        => $webinar->id,
            'webinar_title'     => $webinar->title,
            'total_registrants' => $paginator->total(),
            'registrants'       => $registrations->values(),
            'items'             => $registrations->values(),
            'total'             => $paginator->total(),
            'current_page'      => $paginator->currentPage(),
            'per_page'          => $paginator->perPage(),
            'last_page'         => $paginator->lastPage(),
        ]);
    }

    /**
     * Export webinar registrants as CSV with dynamic custom fields and UTF-8 BOM
     * GET /api/admin/webinars/{slug}/registrants/export
     */
    public function exportRegistrants(Webinar $webinar): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $this->ensureWebinarManager();

        $denied = $this->ensureCanManageWebinar($webinar);
        if ($denied) {
            return $denied;
        }

        $registrations = WebinarRegistration::with('user:id,name,email,mobile')
            ->where('webinar_id', $webinar->id)
            ->oldest()
            ->get();

        // Extract custom fields schema from webinar config
        $customFieldDefs = $webinar->config['form']['customFields'] ?? [];
        $customFieldKeys = [];
        $customFieldHeaders = [];
        if (is_array($customFieldDefs)) {
            foreach ($customFieldDefs as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $key = trim((string) ($f['key'] ?? $f['name'] ?? $f['id'] ?? ''));
                if ($key === '' || $key === '_schema') {
                    continue;
                }
                $customFieldKeys[] = $key;
                $customFieldHeaders[] = $f['label'] ?? $key;
            }
        }

        foreach ($registrations as $reg) {
            $snapshot = is_array($reg->form_responses['_schema'] ?? null) ? $reg->form_responses['_schema'] : [];
            foreach ($snapshot as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = trim((string) ($field['key'] ?? $field['id'] ?? ''));
                if ($key === '' || $key === '_schema' || in_array($key, $customFieldKeys, true)) {
                    continue;
                }
                $customFieldKeys[] = $key;
                $customFieldHeaders[] = $field['label'] ?? $key;
            }
        }

        $filename = 'webinar-registrants-' . $webinar->id . '-' . now()->format('Ymd-His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        $callback = function () use ($registrations, $webinar, $customFieldKeys, $customFieldHeaders) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Microsoft Excel Arabic support
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['Webinar', $webinar->title]);
            fputcsv($handle, ['Date', $webinar->start_at?->format('Y-m-d H:i')]);
            fputcsv($handle, ['Total Registrants', $registrations->count()]);
            fputcsv($handle, []); 

            $csvHeader = [
                '#',
                'Name',
                'Email',
                'Phone',
                'Payment Status',
                'Paid Amount',
                'Attended',
                'Attended At',
                'Registered At',
                'UTM Source',
                ...$customFieldHeaders,
            ];
            fputcsv($handle, $csvHeader);

            foreach ($registrations as $index => $reg) {
                $responses = is_array($reg->form_responses) ? $reg->form_responses : [];
                $customVals = [];
                foreach ($customFieldKeys as $k) {
                    $v = $responses[$k] ?? '';
                    $customVals[] = is_array($v) ? implode(', ', $v) : (string) $v;
                }

                fputcsv($handle, [
                    $index + 1,
                    $reg->user?->name    ?? ($responses['name'] ?? 'N/A'),
                    $reg->user?->email   ?? ($responses['email'] ?? 'N/A'),
                    $reg->user?->mobile  ?? ($responses['whatsapp'] ?? ($responses['phone'] ?? 'N/A')),
                    $reg->payment_status,
                    $reg->paid_amount   ?? '0.00',
                    $reg->attended ? 'Yes' : 'No',
                    $reg->attended_at ? $reg->attended_at->format('Y-m-d H:i:s') : 'N/A',
                    $reg->created_at ? $reg->created_at->format('Y-m-d H:i:s') : 'N/A',
                    $reg->utm_source ?? 'N/A',
                    ...$customVals,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Decode JSON-string nested fields from multipart uploads before validation.
     */
    protected function prepareWebinarPayload(Request $request): void
    {
        $payload = $request->all();
        foreach (['config', 'features'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                continue;
            }
            $decoded = json_decode($payload[$field], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload[$field] = $decoded;
            }
        }
        $request->merge($payload);
    }

    /**
     * Extract the legacy features JSON blob only when an explicit config array is absent.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractLegacyConfigFeature(array &$data): void
    {
        $hasExplicitConfig = isset($data['config']) && is_array($data['config']) && $data['config'] !== [];

        if (!isset($data['features']) || !is_array($data['features'])) {
            return;
        }

        foreach ($data['features'] as $key => $feature) {
            if (!is_string($feature) || !str_starts_with($feature, '__skillso_webinar_config_v1__:')) {
                continue;
            }
            $decoded = json_decode(substr($feature, strlen('__skillso_webinar_config_v1__:')), true);
            unset($data['features'][$key]);
            if (!$hasExplicitConfig && is_array($decoded)) {
                $data['config'] = $decoded;
            }
        }

        $data['features'] = array_values($data['features']);
    }
}
