<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\InstructorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $this->checkPermission('instructors-list'); // Using existing instructor list permission

        $search = $request->input('search');
        $status = $request->input('status');
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = InstructorRequest::query()
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('specialty', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q) => $q->where('status', $status));

        $requests = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $pendingCount = InstructorRequest::where('status', 'pending')->count();

        return $this->jsonSuccess(__('Instructor requests retrieved'), [
            'requests' => $requests,
            'pending_count' => $pendingCount,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $instructorRequest = InstructorRequest::find($id);
        if (!$instructorRequest) {
            return $this->jsonError(__('Instructor request not found'), 404);
        }

        return $this->jsonSuccess(__('Instructor request retrieved'), $instructorRequest);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,contacted,ignored',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $instructorRequest = InstructorRequest::find($id);
        if (!$instructorRequest) {
            return $this->jsonError(__('Instructor request not found'), 404);
        }

        $instructorRequest->update(['status' => $request->status]);

        return $this->jsonSuccess(__('Status updated successfully'), $instructorRequest->fresh());
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

        return $this->jsonSuccess(__('Instructor request deleted successfully'));
    }
}
