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
        $activeSubscriberIds = Subscription::active()
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->pluck('user_id')
            ->unique()
            ->all();

        if (empty($activeSubscriberIds)) {
            return collect();
        }

        // Find courses actively accessed/tracked by active subscribers
        $trackedCoursePairs = DB::table('user_curriculum_trackings as uct')
            ->join('course_chapters as cc', 'uct.course_chapter_id', '=', 'cc.id')
            ->whereIn('uct.user_id', $activeSubscriberIds)
            ->when($request->course_id, fn ($q) => $q->where('cc.course_id', $request->course_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('uct.created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('uct.created_at', '<=', $request->date_to))
            ->selectRaw('uct.user_id, cc.course_id, MIN(uct.created_at) as created_at, MAX(uct.updated_at) as updated_at')
            ->groupBy('uct.user_id', 'cc.course_id')
            ->get();

        $progressCoursePairs = DB::table('user_course_progress as ucp')
            ->whereIn('ucp.user_id', $activeSubscriberIds)
            ->when($request->course_id, fn ($q) => $q->where('ucp.course_id', $request->course_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('ucp.created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('ucp.created_at', '<=', $request->date_to))
            ->selectRaw('ucp.user_id, ucp.course_id, ucp.created_at, ucp.updated_at')
            ->get();

        $mergedPairs = $trackedCoursePairs->concat($progressCoursePairs)
            ->groupBy(fn ($p) => "{$p->user_id}_{$p->course_id}")
            ->map(fn ($group) => $group->first());

        if ($mergedPairs->isEmpty()) {
            return collect();
        }

        $userIds = $mergedPairs->pluck('user_id')->unique()->all();
        $courseIds = $mergedPairs->pluck('course_id')->unique()->all();

        $courses = Course::with('user')->whereIn('id', $courseIds)->get()->keyBy('id');
        $activeSubs = Subscription::with('plan')->active()->whereIn('user_id', $userIds)->get()->groupBy('user_id');

        return $mergedPairs->map(function ($pair) use ($courses, $activeSubs) {
            $course = $courses->get($pair->course_id);
            $sub = $activeSubs->get($pair->user_id)?->first();

            return [
                'id' => 'subscription-' . ($sub?->id ?? 'sub') . '-' . $pair->course_id,
                'source' => 'subscription',
                'user_id' => $pair->user_id,
                'course_id' => $pair->course_id,
                'created_at' => $pair->created_at,
                'updated_at' => $pair->updated_at,
                'order' => null,
                'course' => $course,
                'track' => null,
                'subscription' => $sub,
            ];
        })->filter(fn ($item) => $item['course'] !== null)->values();
    }
}
