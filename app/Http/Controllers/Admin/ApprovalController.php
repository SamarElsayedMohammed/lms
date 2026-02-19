<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseDiscussion;
use App\Models\Rating;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ApprovalController extends Controller
{
    public function pendingRatings(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['ratings-list', 'approve_ratings']);

        $ratings = Rating::with(['user:id,name,email', 'rateable'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'ratings' => $ratings->items(),
            'pagination' => [
                'current_page' => $ratings->currentPage(),
                'last_page' => $ratings->lastPage(),
                'per_page' => $ratings->perPage(),
                'total' => $ratings->total(),
            ],
        ]);
    }

    public function approveRating(int $id)
    {
        ResponseService::noAnyPermissionThenRedirect(['ratings-list', 'approve_ratings']);

        $rating = Rating::findOrFail($id);
        if ($rating->status !== 'pending') {
            ResponseService::errorResponse('Rating is not pending.');
        }

        $rating->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        ResponseService::successResponse('Rating approved successfully.');
    }

    public function rejectRating(int $id)
    {
        ResponseService::noAnyPermissionThenRedirect(['ratings-list', 'approve_ratings']);

        $rating = Rating::findOrFail($id);
        if ($rating->status !== 'pending') {
            ResponseService::errorResponse('Rating is not pending.');
        }

        $rating->update([
            'status' => 'rejected',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        ResponseService::successResponse('Rating rejected.');
    }

    public function pendingComments(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['ratings-list', 'approve_comments']);

        $comments = CourseDiscussion::with(['user:id,name,email', 'course:id,name'])
            ->where('status', 'pending')
            ->whereNull('parent_id')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'comments' => $comments->items(),
            'pagination' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
            ],
        ]);
    }

    public function approveComment(int $id)
    {
        ResponseService::noAnyPermissionThenRedirect(['ratings-list', 'approve_comments']);

        $comment = CourseDiscussion::findOrFail($id);
        if ($comment->status !== 'pending') {
            ResponseService::errorResponse('Comment is not pending.');
        }

        $comment->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        ResponseService::successResponse('Comment approved successfully.');
    }

    public function rejectComment(int $id)
    {
        ResponseService::noAnyPermissionThenRedirect(['ratings-list', 'approve_comments']);

        $comment = CourseDiscussion::findOrFail($id);
        if ($comment->status !== 'pending') {
            ResponseService::errorResponse('Comment is not pending.');
        }

        $comment->update([
            'status' => 'rejected',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        ResponseService::successResponse('Comment rejected.');
    }
}
