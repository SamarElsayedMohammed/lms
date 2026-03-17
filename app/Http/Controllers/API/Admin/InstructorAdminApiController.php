<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Instructor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $query = User::whereHas('instructor_details')
            ->with(['instructor_details.personal_details', 'instructor_details.social_medias'])
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->status, fn ($q) => $q->whereHas('instructor_details', fn ($iq) => $iq->where('status', $request->status)));

        $perPage = min((int) $request->input('per_page', 15), 100);
        $instructors = $query->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Instructors retrieved'), $instructors);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $user = User::with(['instructor_details.personal_details', 'instructor_details.social_medias', 'instructor_details.other_details', 'courses'])
            ->whereHas('instructor_details')
            ->find($id);

        if (!$user) {
            return $this->jsonError(__('Instructor not found'), 404);
        }

        return $this->jsonSuccess(__('Instructor retrieved'), $user);
    }

    public function approve(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $instructor = Instructor::where('user_id', $id)->first();
        if (!$instructor) {
            return $this->jsonError(__('Instructor not found'), 404);
        }

        $instructor->update(['status' => 'approved']);
        return $this->jsonSuccess(__('Instructor approved'), $instructor->fresh(['user']));
    }

    public function reject(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $instructor = Instructor::where('user_id', $id)->first();
        if (!$instructor) {
            return $this->jsonError(__('Instructor not found'), 404);
        }

        $instructor->update(['status' => 'rejected']);
        return $this->jsonSuccess(__('Instructor rejected'), $instructor->fresh(['user']));
    }
}
