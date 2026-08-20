<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Course\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class InstructorCourseMetrics
{
    /**
     * Canonical instructor course count: DISTINCT owned (`courses.user_id`) ∪ assigned (`course_instructors`).
     * Soft-deleted catalog rows are excluded. Draft/unpublished owned courses are included so
     * management and reporting agree.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{courses_count: int, owned_courses_count: int, assigned_courses_count: int, published_courses_count: int}>
     */
    public static function countsForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $empty = [
            'courses_count' => 0,
            'owned_courses_count' => 0,
            'assigned_courses_count' => 0,
            'published_courses_count' => 0,
        ];
        if ($userIds === []) {
            return [];
        }

        $owned = Course::query()
            ->whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'is_active', 'status']);

        $assignedRows = DB::table('course_instructors')
            ->whereIn('user_id', $userIds)
            ->whereNull('deleted_at')
            ->get(['course_id', 'user_id']);

        $assignedCourseIds = $assignedRows->pluck('course_id')->unique()->filter()->values()->all();
        $assignedCourses = $assignedCourseIds === []
            ? collect()
            : Course::query()->whereIn('id', $assignedCourseIds)->get(['id', 'user_id', 'is_active', 'status'])->keyBy('id');

        $result = [];
        foreach ($userIds as $userId) {
            $ownedIds = $owned->where('user_id', $userId)->pluck('id')->all();
            $assignedIds = $assignedRows->where('user_id', $userId)
                ->pluck('course_id')
                ->filter(fn ($courseId) => $assignedCourses->has($courseId))
                ->all();
            $union = collect($ownedIds)->merge($assignedIds)->unique()->values();
            $published = $union->filter(function ($courseId) use ($owned, $assignedCourses) {
                $course = $owned->firstWhere('id', $courseId) ?? $assignedCourses->get($courseId);
                return $course && $course->is_active && $course->status === 'publish';
            });

            $result[$userId] = [
                'courses_count' => $union->count(),
                'owned_courses_count' => count(array_unique($ownedIds)),
                'assigned_courses_count' => count(array_unique($assignedIds)),
                'published_courses_count' => $published->count(),
            ];
        }

        foreach ($userIds as $userId) {
            $result[$userId] ??= $empty;
        }

        return $result;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<int>>
     */
    public static function courseIdsForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        $owned = Course::query()
            ->whereIn('user_id', $userIds)
            ->get(['id', 'user_id']);

        $assignedRows = DB::table('course_instructors')
            ->whereIn('user_id', $userIds)
            ->whereNull('deleted_at')
            ->get(['course_id', 'user_id']);

        $assignedCourseIds = $assignedRows->pluck('course_id')->unique()->filter()->values()->all();
        $existingAssigned = $assignedCourseIds === []
            ? collect()
            : Course::query()->whereIn('id', $assignedCourseIds)->pluck('id')->flip();

        $result = [];
        foreach ($userIds as $userId) {
            $ownedIds = $owned->where('user_id', $userId)->pluck('id')->all();
            $assignedIds = $assignedRows->where('user_id', $userId)
                ->pluck('course_id')
                ->filter(fn ($courseId) => $existingAssigned->has($courseId))
                ->all();
            $result[$userId] = collect($ownedIds)->merge($assignedIds)->unique()->values()->map(fn ($id) => (int) $id)->all();
        }

        return $result;
    }

    /**
     * Unique completed course purchasers and approved ratings on owned ∪ assigned courses.
     * Grain is catalog snapshot (all-time purchases), not instructor.created_at.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{students_count: int, average_rating: float|null, course_ids: list<int>}>
     */
    public static function engagementForUsers(array $userIds): array
    {
        $idsByUser = self::courseIdsForUsers($userIds);
        $allCourseIds = collect($idsByUser)->flatten()->unique()->filter()->values()->all();
        $purchasersByCourse = collect();
        $ratingsByCourse = collect();

        if ($allCourseIds !== []) {
            $purchasersByCourse = DB::table('order_courses')
                ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                ->where('orders.status', 'completed')
                ->whereIn('order_courses.course_id', $allCourseIds)
                ->select('order_courses.course_id', 'orders.user_id')
                ->get()
                ->groupBy('course_id');

            $ratingsByCourse = DB::table('ratings')
                ->where('rateable_type', Course::class)
                ->where('status', 'approved')
                ->whereIn('rateable_id', $allCourseIds)
                ->select('rateable_id', 'rating')
                ->get()
                ->groupBy('rateable_id');
        }

        $result = [];
        foreach ($idsByUser as $userId => $courseIds) {
            $studentIds = collect($courseIds)
                ->flatMap(fn ($courseId) => $purchasersByCourse->get($courseId, collect())->pluck('user_id'))
                ->unique()
                ->filter()
                ->values();
            $ratings = collect($courseIds)
                ->flatMap(fn ($courseId) => $ratingsByCourse->get($courseId, collect())->pluck('rating'))
                ->filter(fn ($rating) => $rating !== null);

            $result[(int) $userId] = [
                'students_count' => $studentIds->count(),
                'average_rating' => $ratings->isEmpty() ? null : round((float) $ratings->avg(), 2),
                'course_ids' => $courseIds,
            ];
        }

        return $result;
    }

    /**
     * @param  list<int>  $userIds
     * @return array{total_students: int, total_enrollments: int, total_revenue_egp: float, average_rating: float|null}
     */
    public static function globalEngagement(array $userIds): array
    {
        $idsByUser = self::courseIdsForUsers($userIds);
        $courseIds = collect($idsByUser)->flatten()->unique()->filter()->values()->all();
        if ($courseIds === []) {
            return [
                'total_students' => 0,
                'total_enrollments' => 0,
                'total_revenue_egp' => 0.0,
                'average_rating' => null,
            ];
        }

        $purchaseRows = DB::table('order_courses')
            ->join('orders', 'order_courses.order_id', '=', 'orders.id')
            ->where('orders.status', 'completed')
            ->whereIn('order_courses.course_id', $courseIds)
            ->selectRaw('orders.user_id, order_courses.id as purchase_id, ' . ReportMoneySql::orderCourseRevenueEgpSql('order_courses') . ' as revenue_egp')
            ->get();

        $avgRating = DB::table('ratings')
            ->where('rateable_type', Course::class)
            ->where('status', 'approved')
            ->whereIn('rateable_id', $courseIds)
            ->avg('rating');

        return [
            'total_students' => $purchaseRows->pluck('user_id')->unique()->filter()->count(),
            'total_enrollments' => $purchaseRows->count(),
            'total_revenue_egp' => round((float) $purchaseRows->sum('revenue_egp'), 2),
            'average_rating' => $avgRating === null ? null : round((float) $avgRating, 2),
        ];
    }

    /**
     * @return array{courses_count: int, owned_courses_count: int, assigned_courses_count: int, published_courses_count: int}
     */
    public static function countsForUser(int $userId): array
    {
        return self::countsForUsers([$userId])[$userId] ?? [
            'courses_count' => 0,
            'owned_courses_count' => 0,
            'assigned_courses_count' => 0,
            'published_courses_count' => 0,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $instructors
     * @return array<int, array{courses_count: int, owned_courses_count: int, assigned_courses_count: int, published_courses_count: int}>
     */
    public static function countsForInstructors(Collection $instructors): array
    {
        $userIds = $instructors
            ->map(static fn ($instructor) => (int) ($instructor->user_id ?? $instructor->id ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return self::countsForUsers($userIds);
    }
}
