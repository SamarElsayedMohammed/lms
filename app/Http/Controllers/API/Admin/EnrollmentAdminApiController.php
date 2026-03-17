<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\OrderCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('enrollments-list');

        $query = OrderCourse::with(['order.user', 'course.user'])
            ->whereHas('order', fn ($q) => $q->where('status', 'completed'))
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->user_id, fn ($q) => $q->whereHas('order', fn ($oq) => $oq->where('user_id', $request->user_id)))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to));

        $perPage = min((int) $request->input('per_page', 15), 100);
        $enrollments = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Enrollments retrieved'), $enrollments);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('enrollments-list');

        $enrollment = OrderCourse::with(['order.user', 'course.user', 'promoCode'])->find($id);
        if (!$enrollment) {
            return $this->jsonError(__('Enrollment not found'), 404);
        }

        return $this->jsonSuccess(__('Enrollment retrieved'), $enrollment);
    }
}
