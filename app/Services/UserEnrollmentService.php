<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Course\UserCourseTrack;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which courses a user is enrolled in / can access.
 *
 * Sources (same as my-learning):
 * 1. Completed orders (order_courses), excluding refunded purchases
 * 2. user_course_tracks rows
 * 3. Active subscription → all published catalog courses
 */
final class UserEnrollmentService
{
    /**
     * @return Collection<int, array{course_id: int, enrolled_at: Carbon, access_started_at: Carbon, purchase_date: Carbon|null, source: string}>
     */
    public function resolveEnrolledCourseIds(int $userId): Collection
    {
        $user = User::find($userId);
        if ($user === null) {
            return collect();
        }

        $refundedCoursesByOrder = $this->getRefundedCoursesByOrder($userId);
        $enrolled = collect();

        $orders = Order::query()
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->with(['orderCourses.course'])
            ->get();

        foreach ($orders as $order) {
            $orderId = (int) $order->id;
            $orderDate = Carbon::parse($order->created_at);

            foreach ($order->orderCourses as $orderCourse) {
                $course = $orderCourse->course;
                if (!$this->isAccessibleCourse($course)) {
                    continue;
                }

                $courseId = (int) $course->id;

                if ($this->isRefundedPurchase($refundedCoursesByOrder, $courseId, $orderId, $orderDate)) {
                    continue;
                }

                $this->upsertEnrollment($enrolled, $courseId, $orderDate, $orderDate, 'purchase');
            }
        }

        UserCourseTrack::query()
            ->where('user_id', $userId)
            ->with('course')
            ->get()
            ->each(function (UserCourseTrack $track) use ($enrolled): void {
                if (!$this->isAccessibleCourse($track->course)) {
                    return;
                }

                $this->upsertEnrollment(
                    $enrolled,
                    (int) $track->course_id,
                    Carbon::parse($track->created_at),
                    null,
                    'track',
                );
            });

        $activeSubscription = app(SubscriptionService::class)->getActiveSubscription($user);
        if ($activeSubscription !== null) {
            $accessStartedAt = Carbon::parse(
                $activeSubscription->starts_at ?? $activeSubscription->created_at,
            );
            Course::query()
                ->where('status', 'publish')
                ->where('approval_status', 'approved')
                ->where('is_active', true)
                ->pluck('id')
                ->each(function ($courseId) use ($enrolled, $accessStartedAt): void {
                    $this->upsertEnrollment(
                        $enrolled,
                        (int) $courseId,
                        $accessStartedAt,
                        null,
                        'subscription',
                    );
                });
        }

        return $enrolled;
    }

    /**
     * @return Collection<int, array{course_id: int, course: Course, enrolled_at: Carbon, access_started_at: Carbon, purchase_date: Carbon|null, source: string}>
     */
    public function resolveEnrolledCourses(int $userId, ?callable $courseQueryModifier = null): Collection
    {
        $enrolled = $this->resolveEnrolledCourseIds($userId);

        if ($enrolled->isEmpty()) {
            return collect();
        }

        $courseIds = $enrolled->pluck('course_id')->unique()->values()->all();

        $coursesQuery = Course::query()->whereIn('id', $courseIds);

        if ($courseQueryModifier !== null) {
            $courseQueryModifier($coursesQuery);
        }

        $coursesById = $coursesQuery->get()->keyBy('id');

        return $enrolled
            ->map(static function (array $item) use ($coursesById) {
                $course = $coursesById->get($item['course_id']);

                if ($course === null
                    || !$course->is_active
                    || $course->status !== 'publish'
                    || $course->approval_status !== 'approved'
                ) {
                    return null;
                }

                return [
                    'course_id' => $item['course_id'],
                    'course' => $course,
                    'enrolled_at' => $item['enrolled_at'],
                    'access_started_at' => $item['access_started_at'],
                    'purchase_date' => $item['purchase_date'],
                    'source' => $item['source'],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Eager-load shape used by GET /api/my-learning.
     */
    public function applyMyLearningCourseEagerLoad($query): void
    {
        $query
            ->with([
                'category',
                'user',
                'taxes',
                'ratings.user',
                'chapters' => static function ($chapterQuery): void {
                    $chapterQuery
                        ->where('is_active', true)
                        ->with([
                            'lectures' => static fn ($lectureQuery) => $lectureQuery->where('is_active', true),
                            'quizzes' => static fn ($quizQuery) => $quizQuery->where('is_active', true),
                            'assignments' => static fn ($assignmentQuery) => $assignmentQuery->where('is_active', true),
                            'resources' => static fn ($resourceQuery) => $resourceQuery->where('is_active', true),
                        ]);
                },
            ])
            ->withAvg(['ratings' => static function ($q): void { $q->where('status', 'approved'); }], 'rating')
            ->withCount(['ratings' => static function ($q): void { $q->where('status', 'approved'); }])
            ->where('status', 'publish')
            ->where('approval_status', 'approved')
            ->where('is_active', true);
    }

    public function applyUserEnrolledCoursesEagerLoad($query): void
    {
        $query
            ->with([
                'chapters' => static function ($chapterQuery): void {
                    $chapterQuery
                        ->where('is_active', true)
                        ->with(['lectures', 'quizzes', 'assignments', 'resources']);
                },
            ])
            ->where('is_active', true)
            ->where('status', 'publish')
            ->where('approval_status', 'approved')
            ->whereHas('chapters', static function ($chapterQuery): void {
                $chapterQuery
                    ->where('is_active', true)
                    ->where(static function ($curriculumQuery): void {
                        $curriculumQuery
                            ->whereHas('lectures', static fn ($q) => $q->where('is_active', true))
                            ->orWhereHas('quizzes', static fn ($q) => $q->where('is_active', true))
                            ->orWhereHas('assignments', static fn ($q) => $q->where('is_active', true))
                            ->orWhereHas('resources', static fn ($q) => $q->where('is_active', true));
                    });
            });
    }

    private function getRefundedCoursesByOrder(int $userId): Collection
    {
        return DB::table('refund_requests')
            ->join('transactions', 'refund_requests.transaction_id', '=', 'transactions.id')
            ->where('refund_requests.user_id', $userId)
            ->where('refund_requests.status', 'approved')
            ->whereNotNull('refund_requests.transaction_id')
            ->whereNotNull('transactions.order_id')
            ->select(
                'refund_requests.course_id',
                'transactions.order_id',
                'refund_requests.processed_at',
                'refund_requests.created_at',
            )
            ->get()
            ->groupBy('course_id')
            ->map(static fn ($refunds) => $refunds->mapWithKeys(static function ($refund) {
                $orderId = (int) $refund->order_id;
                $refundDate = $refund->processed_at ?? $refund->created_at;

                return [$orderId => Carbon::parse($refundDate)];
            }));
    }

    private function isAccessibleCourse(?Course $course): bool
    {
        return $course !== null
            && $course->status === 'publish'
            && $course->approval_status === 'approved'
            && (bool) $course->is_active;
    }

    private function isRefundedPurchase(
        Collection $refundedCoursesByOrder,
        int $courseId,
        int $orderId,
        Carbon $orderDate,
    ): bool {
        $courseRefunds = $refundedCoursesByOrder->get($courseId);
        if ($courseRefunds === null) {
            return false;
        }

        $refundDate = $courseRefunds->get($orderId);

        return $refundDate !== null && $orderDate->lte($refundDate);
    }

    /**
     * @param Collection<int, array{course_id: int, enrolled_at: Carbon, access_started_at: Carbon, purchase_date: Carbon|null, source: string}> $enrolled
     */
    private function upsertEnrollment(
        Collection $enrolled,
        int $courseId,
        Carbon $enrolledAt,
        ?Carbon $purchaseDate,
        string $source,
    ): void
    {
        $existingIndex = $enrolled->search(static fn (array $item) => $item['course_id'] === $courseId);

        if ($existingIndex === false) {
            $enrolled->push([
                'course_id' => $courseId,
                'enrolled_at' => $enrolledAt,
                'access_started_at' => $enrolledAt,
                'purchase_date' => $purchaseDate,
                'source' => $source,
            ]);

            return;
        }

        $existing = $enrolled[$existingIndex];
        $existing['enrolled_at'] = $enrolledAt->lt($existing['enrolled_at'])
            ? $enrolledAt
            : $existing['enrolled_at'];
        $existing['access_started_at'] = $enrolledAt->lt($existing['access_started_at'])
            ? $enrolledAt
            : $existing['access_started_at'];

        if ($purchaseDate !== null && ($existing['purchase_date'] === null || $purchaseDate->lt($existing['purchase_date']))) {
            $existing['purchase_date'] = $purchaseDate;
        }

        // A direct purchase is the strongest durable acquisition source.
        if ($source === 'purchase' || $existing['source'] === 'subscription') {
            $existing['source'] = $source;
        }

        $enrolled[$existingIndex] = $existing;
    }
}
