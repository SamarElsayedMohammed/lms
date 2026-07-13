<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\User;

use App\Models\Course\Course;
use App\Models\Course\UserCourseTrack;
use App\Models\OrderCourse;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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

        $perPage = min((int) $request->input('per_page', 15), 100);
        $page = max((int) $request->input('page', 1), 1);

        $enrollments = collect()
            ->merge($this->purchaseEnrollments($request))
            ->merge($this->trackEnrollments($request))
            ->merge($this->subscriptionEnrollments($request))
            ->sortByDesc('created_at')
            ->values();

        $paginated = new LengthAwarePaginator(
            $enrollments->forPage($page, $perPage)->values(),
            $enrollments->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return $this->jsonSuccess(__('Enrollments retrieved'), $paginated);
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

    private function purchaseEnrollments(Request $request): Collection
    {
        return OrderCourse::with(['order.user', 'course.user'])
            ->whereHas('order', fn ($q) => $q->where('status', 'completed'))
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->user_id, fn ($q) => $q->whereHas('order', fn ($oq) => $oq->where('user_id', $request->user_id)))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->get()
            ->map(fn (OrderCourse $enrollment) => [
                'id' => $enrollment->id,
                'source' => 'purchase',
                'user_id' => $enrollment->order?->user_id,
                'course_id' => $enrollment->course_id,
                'created_at' => $enrollment->created_at,
                'updated_at' => $enrollment->updated_at,
                'order' => $enrollment->order,
                'course' => $enrollment->course,
                'track' => null,
                'subscription' => null,
            ]);
    }

    private function trackEnrollments(Request $request): Collection
    {
        return UserCourseTrack::with(['user', 'course.user'])
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->get()
            ->map(fn (UserCourseTrack $track) => [
                'id' => 'track-' . $track->id,
                'source' => 'track',
                'user_id' => $track->user_id,
                'course_id' => $track->course_id,
                'created_at' => $track->created_at,
                'updated_at' => $track->updated_at,
                'order' => null,
                'course' => $track->course,
                'track' => $track,
                'subscription' => null,
            ]);
    }

    private function subscriptionEnrollments(Request $request): Collection
    {
        $courses = Course::with('user')
            ->where('status', 'publish')
            ->where('approval_status', 'approved')
            ->where('is_active', true)
            ->whereHasContent()
            ->when($request->course_id, fn ($q) => $q->where('id', $request->course_id))
            ->get();

        if ($courses->isEmpty()) {
            return collect();
        }

        return Subscription::with(['user', 'plan'])
            ->active()
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->get()
            ->flatMap(function (Subscription $subscription) use ($courses) {
                return $courses->map(fn (Course $course) => [
                    'id' => 'subscription-' . $subscription->id . '-' . $course->id,
                    'source' => 'subscription',
                    'user_id' => $subscription->user_id,
                    'course_id' => $course->id,
                    'created_at' => $subscription->created_at,
                    'updated_at' => $subscription->updated_at,
                    'order' => null,
                    'course' => $course,
                    'track' => null,
                    'subscription' => $subscription,
                ]);
            });
    }
}
