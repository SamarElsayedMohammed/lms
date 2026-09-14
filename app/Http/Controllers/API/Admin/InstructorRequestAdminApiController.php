<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\InstructorRequest;
use App\Services\AuditLogService;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class InstructorRequestAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $search = $request->input('search');
        $status = $request->input('status');
        $specialty = $request->input('specialty');
        $hasCv = $request->input('has_cv');
        $hasVideo = $request->input('has_video');
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = InstructorRequest::query()
            ->with([
                'reviewer:id,name,email',
                'user:id,name,email',
            ])
            ->when($search, function ($q) use ($search) {
                $cleanSearch = trim((string) $search);
                $cleanSearch = strtr($cleanSearch, [
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                    '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                ]);
                $q->where(function ($sq) use ($cleanSearch) {
                    $sq->where('name', 'like', "%{$cleanSearch}%")
                        ->orWhere('first_name', 'like', "%{$cleanSearch}%")
                        ->orWhere('last_name', 'like', "%{$cleanSearch}%")
                        ->orWhere('email', 'like', "%{$cleanSearch}%")
                        ->orWhere('phone', 'like', "%{$cleanSearch}%")
                        ->orWhere('specialty', 'like', "%{$cleanSearch}%")
                        ->orWhere('company', 'like', "%{$cleanSearch}%")
                        ->orWhere('job_title', 'like', "%{$cleanSearch}%");

                    if (is_numeric($cleanSearch)) {
                        $sq->orWhere('id', (int) $cleanSearch);
                    } elseif (preg_match('/(?:EXP|REQ)?(?:-|\s)*(?:\d{4})?(?:-|\s)*(\d+)/i', $cleanSearch, $matches)) {
                        $sq->orWhere('id', (int) ltrim($matches[1], '0'));
                    }
                });
            })
            ->when($status && $status !== 'all', function ($q) use ($status) {
                if ($status === 'needs_attention') {
                    $q->whereIn('status', ['pending', 'resubmitted']);
                } else {
                    $q->where('status', $status);
                }
            })
            ->when($hasCv !== null && $hasCv !== '', function ($q) use ($hasCv) {
                if (in_array($hasCv, ['1', 1, 'true', true], true)) {
                    $q->whereNotNull('cv_path');
                } elseif (in_array($hasCv, ['0', 0, 'false', false], true)) {
                    $q->whereNull('cv_path');
                }
            })
            ->when($hasVideo !== null && $hasVideo !== '', function ($q) use ($hasVideo) {
                if (in_array($hasVideo, ['1', 1, 'true', true], true)) {
                    $q->where(function ($vq) {
                        $vq->whereNotNull('intro_video_path')
                            ->orWhereNotNull('intro_video_url');
                    });
                } elseif (in_array($hasVideo, ['0', 0, 'false', false], true)) {
                    $q->whereNull('intro_video_path')
                        ->whereNull('intro_video_url');
                }
            })
            ->when($specialty, function ($q) use ($specialty) {
                $q->where('specialty', 'like', "%{$specialty}%");
            });

        $requests = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Comprehensive status metric counts for admin dashboard
        $counts = [
            'total' => InstructorRequest::count(),
            'needs_attention' => InstructorRequest::whereIn('status', ['pending', 'resubmitted'])->count(),
            'pending' => InstructorRequest::where('status', 'pending')->count(),
            'under_review' => InstructorRequest::where('status', 'under_review')->count(),
            'changes_requested' => InstructorRequest::where('status', 'changes_requested')->count(),
            'resubmitted' => InstructorRequest::where('status', 'resubmitted')->count(),
            'approved' => InstructorRequest::where('status', 'approved')->count(),
            'rejected' => InstructorRequest::where('status', 'rejected')->count(),
        ];

        return $this->jsonSuccess(__('Instructor requests retrieved'), [
            'requests' => $requests,
            'items' => $requests,
            'pending_count' => $counts['pending'],
            'needs_attention_count' => $counts['needs_attention'],
            'metrics' => $counts,
            'status_breakdown' => $counts,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $instructorRequest = InstructorRequest::with([
            'reviewer:id,name,email',
            'user:id,name,email',
            'auditLogs',
        ])->find($id);

        if (!$instructorRequest) {
            return $this->jsonError(__('Instructor request not found'), 404);
        }

        return $this->jsonSuccess(__('Instructor request retrieved'), $instructorRequest);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $allowedStatuses = implode(',', array_keys(InstructorRequest::getStatusOptions()));

        $validator = Validator::make($request->all(), [
            'status' => "required|in:{$allowedStatuses}",
            'admin_notes' => 'nullable|string|max:2000',
            'applicant_feedback' => 'nullable|string|max:2000',
            'rejection_reason' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $instructorRequest = InstructorRequest::find($id);
        if (!$instructorRequest) {
            return $this->jsonError(__('Instructor request not found'), 404);
        }

        $oldStatus = $instructorRequest->status;
        $newStatus = (string) $request->input('status');

        $updateData = [
            'status' => $newStatus,
            'reviewer_id' => Auth::id(),
            'reviewed_at' => now(),
        ];

        if ($request->has('admin_notes')) {
            $updateData['admin_notes'] = $request->input('admin_notes');
        }

        if ($request->has('applicant_feedback')) {
            $updateData['applicant_feedback'] = $request->input('applicant_feedback');
        }

        if ($request->has('rejection_reason')) {
            $updateData['rejection_reason'] = $request->input('rejection_reason');
        }

        $instructorRequest->update($updateData);

        // Immutable admin audit log
        try {
            AuditLogService::log(
                action: 'instructor_request_status_updated',
                target: $instructorRequest,
                summary: "Updated instructor request #{$id} status from '{$oldStatus}' to '{$newStatus}'",
                details: [
                    'request_id' => $id,
                    'applicant_name' => $instructorRequest->name,
                    'applicant_email' => $instructorRequest->email,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'admin_notes' => $request->input('admin_notes'),
                    'applicant_feedback' => $request->input('applicant_feedback'),
                    'rejection_reason' => $request->input('rejection_reason'),
                ]
            );
        } catch (\Throwable) {
            // Do not block status update if audit log throws
        }

        return $this->jsonSuccess(__('Status updated successfully'), $instructorRequest->fresh([
            'reviewer:id,name,email',
            'user:id,name,email',
        ]));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $instructorRequest = InstructorRequest::find($id);
        if (!$instructorRequest) {
            return $this->jsonError(__('Instructor request not found'), 404);
        }

        $instructorRequest->delete();

        try {
            AuditLogService::log(
                action: 'instructor_request_deleted',
                target: $instructorRequest,
                summary: "Deleted instructor request #{$id} ({$instructorRequest->name})",
                details: ['id' => $id, 'email' => $instructorRequest->email]
            );
        } catch (\Throwable) {
            // Pass through
        }

        return $this->jsonSuccess(__('Instructor request deleted successfully'));
    }
}
