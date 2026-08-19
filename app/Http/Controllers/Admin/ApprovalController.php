<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseDiscussion;
use App\Models\Instructor;
use App\Models\Rating;
use App\Notifications\ReviewStatusNotification;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ApprovalController extends Controller
{
    public function pendingRatings(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        $paginated = Rating::with([
            'user:id,name,profile',
            'rateable' => function ($morphTo): void {
                $morphTo->morphWith([
                    Instructor::class => ['user:id,name'],
                ]);
            },
        ])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $paginated->setCollection(
            $paginated->getCollection()->map(fn (Rating $r) => $r->toAdminArray())
        );

        return ApiResponseService::successResponse('Pending ratings retrieved successfully', $paginated);
    }

    public function approveRating(int $id)
    {
        $rating = Rating::find($id);
        if (!$rating) {
            return ApiResponseService::errorResponse('Rating not found.', null, 404);
        }
        if ($rating->status !== 'pending') {
            return ApiResponseService::errorResponse('Rating is not pending.');
        }

        $rating->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        $this->notifyAuthor($rating, 'approved');

        return ApiResponseService::successResponse('Rating approved successfully.');
    }

    public function rejectRating(int $id)
    {
        $rating = Rating::find($id);
        if (!$rating) {
            return ApiResponseService::errorResponse('Rating not found.', null, 404);
        }
        if ($rating->status !== 'pending') {
            return ApiResponseService::errorResponse('Rating is not pending.');
        }

        $rating->update([
            'status' => 'rejected',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        $this->notifyAuthor($rating, 'rejected');

        return ApiResponseService::successResponse('Rating rejected.');
    }

    public function pendingComments(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        $paginated = CourseDiscussion::with(['user:id,name,profile', 'course:id,title'])
            ->where('status', 'pending')
            ->whereNull('parent_id')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $paginated->setCollection(
            $paginated->getCollection()->map(function (CourseDiscussion $comment) {
                $title = $comment->course?->title;

                return [
                    'id' => $comment->id,
                    'rating' => 0,
                    'review' => $comment->message,
                    'comment' => $comment->message,
                    'user' => [
                        'id' => $comment->user?->id,
                        'name' => $comment->user?->name ?: 'مستخدم',
                        'image' => $comment->user?->profile,
                    ],
                    'user_name' => $comment->user?->name ?: 'مستخدم',
                    'course' => $title ? ['title' => $title] : null,
                    'course_title' => $title,
                    'status' => $comment->status ?? 'pending',
                    'created_at' => $comment->created_at?->toIso8601String(),
                ];
            })
        );

        return ApiResponseService::successResponse('Pending comments retrieved successfully', $paginated);
    }

    public function approveComment(int $id)
    {
        $comment = CourseDiscussion::find($id);
        if (!$comment) {
            return ApiResponseService::errorResponse('Comment not found.', null, 404);
        }
        if ($comment->status !== 'pending') {
            return ApiResponseService::errorResponse('Comment is not pending.');
        }

        $comment->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        if ($comment->user) {
            try {
                $comment->user->notify(new ReviewStatusNotification($comment, 'comment', 'approved'));
            } catch (\Throwable) {
            }
        }

        return ApiResponseService::successResponse('Comment approved successfully.');
    }

    public function rejectComment(int $id)
    {
        $comment = CourseDiscussion::find($id);
        if (!$comment) {
            return ApiResponseService::errorResponse('Comment not found.', null, 404);
        }
        if ($comment->status !== 'pending') {
            return ApiResponseService::errorResponse('Comment is not pending.');
        }

        $comment->update([
            'status' => 'rejected',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        if ($comment->user) {
            try {
                $comment->user->notify(new ReviewStatusNotification($comment, 'comment', 'rejected'));
            } catch (\Throwable) {
            }
        }

        return ApiResponseService::successResponse('Comment rejected.');
    }

    private function notifyAuthor(Rating $rating, string $status): void
    {
        if (!$rating->user) {
            return;
        }

        try {
            $rating->user->notify(new ReviewStatusNotification($rating, 'rating', $status));
        } catch (\Throwable) {
        }
    }
}
