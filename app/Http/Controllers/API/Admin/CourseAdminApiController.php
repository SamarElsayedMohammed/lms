<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Course\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-list');

        $query = Course::without('taxes')
            ->with(['user', 'category'])
            ->when($request->search, fn ($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->approval_status, fn ($q) => $q->where('approval_status', $request->approval_status))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->with_trashed, fn ($q) => $q->withTrashed());

        $perPage = min((int) $request->input('per_page', 15), 100);
        $courses = $query->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Courses retrieved'), $courses);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-list');

        $course = Course::without('taxes')
            ->with(['user', 'category', 'chapters', 'tags'])
            ->withTrashed()
            ->find($id);

        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        return $this->jsonSuccess(__('Course retrieved'), $course);
    }

    public function approve(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $course = Course::find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $course->update(['approval_status' => 'approved']);
        return $this->jsonSuccess(__('Course approved'), $course->fresh());
    }

    public function reject(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $course = Course::find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $course->update(['approval_status' => 'rejected']);
        return $this->jsonSuccess(__('Course rejected'), $course->fresh());
    }

    public function restore(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $course = Course::onlyTrashed()->find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $course->restore();
        return $this->jsonSuccess(__('Course restored'), $course->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-delete');

        $course = Course::find($id);
        if (!$course) {
            return $this->jsonError(__('Course not found'), 404);
        }

        $course->delete();
        return $this->jsonSuccess(__('Course deleted'));
    }
}
