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
use Illuminate\Support\Facades\DB;

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
        $offset = ($page - 1) * $perPage;

        // 1. Purchase enrollments query
        $purchaseQuery = DB::table('order_courses')
            ->join('orders', 'order_courses.order_id', '=', 'orders.id')
            ->where('orders.status', 'completed')
            ->when($request->course_id, fn ($q) => $q->where('order_courses.course_id', $request->course_id))
            ->when($request->user_id, fn ($q) => $q->where('orders.user_id', $request->user_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('order_courses.created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('order_courses.created_at', '<=', $request->date_to))
            ->selectRaw("CAST(order_courses.id AS CHAR) as record_id, order_courses.created_at as created_at, 'purchase' as source, orders.user_id as user_id, order_courses.course_id as course_id");

        // 2. Track enrollments query
        $trackQuery = DB::table('user_course_tracks')
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->selectRaw("CAST(user_course_tracks.id AS CHAR) as record_id, user_course_tracks.created_at as created_at, 'track' as source, user_course_tracks.user_id as user_id, user_course_tracks.course_id as course_id");

        // 3. Subscription enrollments query
        $subCurriculumQuery = DB::table('user_curriculum_trackings as uct')
            ->join('course_chapters as cc', 'uct.course_chapter_id', '=', 'cc.id')
            ->join('subscriptions as s', function ($join) {
                $join->on('uct.user_id', '=', 's.user_id')
                    ->where('s.status', '=', 'active')
                    ->where(function ($q) {
                        $q->whereNull('s.ends_at')->orWhere('s.ends_at', '>', now());
                    });
            })
            ->when($request->course_id, fn ($q) => $q->where('cc.course_id', $request->course_id))
            ->when($request->user_id, fn ($q) => $q->where('uct.user_id', $request->user_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('uct.created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('uct.created_at', '<=', $request->date_to))
            ->selectRaw("CAST(MIN(uct.id) AS CHAR) as record_id, MIN(uct.created_at) as created_at, 'subscription' as source, uct.user_id as user_id, cc.course_id as course_id")
            ->groupBy('uct.user_id', 'cc.course_id');

        $union = (clone $purchaseQuery)
            ->toBase()
            ->unionAll((clone $trackQuery)->toBase())
            ->unionAll((clone $subCurriculumQuery)->toBase());

        $total = DB::query()->fromSub($union, 'combined_count')->count();

        $pageRows = DB::query()
            ->fromSub($union, 'combined_enrollments')
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        // Batch-hydrate relations for the current page only
        $purchaseIds = $pageRows->where('source', 'purchase')->pluck('record_id')->map(fn ($id) => (int) $id)->all();
        $trackIds = $pageRows->where('source', 'track')->pluck('record_id')->map(fn ($id) => (int) $id)->all();
        $subUserIds = $pageRows->where('source', 'subscription')->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all();
        $courseIds = $pageRows->pluck('course_id')->map(fn ($id) => (int) $id)->unique()->filter()->all();

        $orderCoursesById = !empty($purchaseIds)
            ? OrderCourse::with(['order.user', 'course.user'])->whereIn('id', $purchaseIds)->get()->keyBy('id')
            : collect();

        $tracksById = !empty($trackIds)
            ? UserCourseTrack::with(['user', 'course.user'])->whereIn('id', $trackIds)->get()->keyBy('id')
            : collect();

        $coursesById = !empty($courseIds)
            ? Course::with('user')->whereIn('id', $courseIds)->get()->keyBy('id')
            : collect();

        $activeSubsByUser = !empty($subUserIds)
            ? Subscription::with('plan')->active()->whereIn('user_id', $subUserIds)->get()->groupBy('user_id')
            : collect();

        $currentItems = $pageRows->map(function ($row) use ($orderCoursesById, $tracksById, $coursesById, $activeSubsByUser) {
            $recordId = (int) $row->record_id;
            $courseId = (int) $row->course_id;
            $userId = (int) $row->user_id;

            if ($row->source === 'purchase') {
                $enrollment = $orderCoursesById->get($recordId);
                return [
                    'id' => $enrollment?->id ?? $recordId,
                    'source' => 'purchase',
                    'user_id' => $enrollment?->order?->user_id ?? $userId,
                    'course_id' => $enrollment?->course_id ?? $courseId,
                    'created_at' => $enrollment?->created_at ?? $row->created_at,
                    'updated_at' => $enrollment?->updated_at ?? $row->created_at,
                    'order' => $enrollment?->order,
                    'course' => $enrollment?->course ?? $coursesById->get($courseId),
                    'track' => null,
                    'subscription' => null,
                ];
            }

            if ($row->source === 'track') {
                $track = $tracksById->get($recordId);
                return [
                    'id' => 'track-' . ($track?->id ?? $recordId),
                    'source' => 'track',
                    'user_id' => $track?->user_id ?? $userId,
                    'course_id' => $track?->course_id ?? $courseId,
                    'created_at' => $track?->created_at ?? $row->created_at,
                    'updated_at' => $track?->updated_at ?? $row->created_at,
                    'order' => null,
                    'course' => $track?->course ?? $coursesById->get($courseId),
                    'track' => $track,
                    'subscription' => null,
                ];
            }

            $sub = $activeSubsByUser->get($userId)?->first();
            $course = $coursesById->get($courseId);

            return [
                'id' => 'subscription-' . ($sub?->id ?? 'sub') . '-' . $courseId,
                'source' => 'subscription',
                'user_id' => $userId,
                'course_id' => $courseId,
                'created_at' => $row->created_at,
                'updated_at' => $row->created_at,
                'order' => null,
                'course' => $course,
                'track' => null,
                'subscription' => $sub,
            ];
        });

        $paginated = new LengthAwarePaginator(
            $currentItems->values(),
            $total,
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
}
