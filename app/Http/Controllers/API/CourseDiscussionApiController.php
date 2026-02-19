<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseDiscussion;
use App\Models\Order;
use App\Services\ApiResponseService;
use App\Services\FeatureFlagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CourseDiscussionApiController extends Controller
{
    public function getCourseDiscussion(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_id' => 'nullable|exists:courses,id',
                'course_slug' => 'nullable|exists:courses,slug',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'search' => 'nullable|string|max:255', // Search in messages and replies
            ]);

            // Custom validation to ensure either course_id or course_slug is provided
            if (!$request->has('course_id') && !$request->has('course_slug')) {
                return ApiResponseService::validationError('Either course_id or course_slug is required.');
            }

            if ($request->has('course_id') && $request->has('course_slug')) {
                return ApiResponseService::validationError('Please provide either course_id or course_slug, not both.');
            }

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors());
            }

            $perPage = $request->input('per_page', 15); // Default to 15 if not provided
            $currentPage = $request->input('page', 1);
            $searchTerm = $request->input('search');

            // Determine course_id from either course_id or course_slug
            $courseId = null;
            if ($request->has('course_id')) {
                $courseId = $request->course_id;
            } else {
                // Get course_id from course_slug
                $course = \App\Models\Course\Course::where('slug', $request->course_slug)->first();
                if (!$course) {
                    return ApiResponseService::validationError('Course not found with the provided slug.');
                }
                $courseId = $course->id;
            }

            $applyApproval = FeatureFlagService::isEnabled('comments_require_approval');

            // Get total count of all discussions for this course (without search filter)
            $baseQuery = CourseDiscussion::where('course_id', $courseId)->whereNull('parent_id');
            $allDiscussionCount = $applyApproval ? $baseQuery->approved()->count() : $baseQuery->count();

            // Get filtered count if search is applied
            $filteredDiscussionCount = null;
            if ($searchTerm) {
                $searchQuery = CourseDiscussion::where('course_id', $courseId)
                    ->whereNull('parent_id')
                    ->where(static function ($query) use ($searchTerm): void {
                        $query->where(
                            'message',
                            'LIKE',
                            "%{$searchTerm}%",
                        )->orWhereHas('replies', static function ($replyQuery) use ($searchTerm): void {
                            $replyQuery->where('message', 'LIKE', "%{$searchTerm}%");
                        });
                    });
                $filteredDiscussionCount = $applyApproval ? $searchQuery->approved()->count() : $searchQuery->count();
            }

            // Build query for discussions
            $repliesConstraint = $applyApproval
                ? fn($q) => $q->with('user')->approved()
                : fn($q) => $q->with('user');
            $discussionsQuery = CourseDiscussion::with(['user', 'replies' => $repliesConstraint])
                ->where('course_id', $courseId)
                ->whereNull('parent_id');
            if ($applyApproval) {
                $discussionsQuery->approved();
            }

            // Apply search filter if search term is provided
            if ($searchTerm) {
                $discussionsQuery->where(static function ($query) use ($searchTerm): void {
                    $query->where(
                        'message',
                        'LIKE',
                        "%{$searchTerm}%",
                    )->orWhereHas('replies', static function ($replyQuery) use ($searchTerm): void {
                        $replyQuery->where('message', 'LIKE', "%{$searchTerm}%");
                    });
                });
            }

            // Fetch discussions with pagination
            $discussions = $discussionsQuery->latest()->paginate($perPage, ['*'], 'page', $currentPage);

            // Transform discussions to add time_ago and reply_count
            $transformedDiscussions = $discussions->getCollection()->map(static function ($discussion) {
                // Add time_ago for main discussion
                $discussion->time_ago = $discussion->created_at->diffForHumans();

                // Add reply_count for main discussion
                $discussion->reply_count = $discussion->replies->count();

                // Transform replies to add time_ago
                $discussion->replies = $discussion->replies->map(static function ($reply) {
                    $reply->time_ago = $reply->created_at->diffForHumans();
                    return $reply;
                });

                return $discussion;
            });

            // Replace the collection in pagination
            $discussions->setCollection($transformedDiscussions);

            // Add counts to the response
            $discussions->all_discussion_count = $allDiscussionCount;
            if ($filteredDiscussionCount !== null) {
                $discussions->filtered_discussion_count = $filteredDiscussionCount;
                $discussions->search_term = $searchTerm;
            }

            // Return standard Laravel pagination response with additional data
            return ApiResponseService::successResponse('Course discussions fetched successfully', $discussions);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get course discussions');
            return ApiResponseService::errorResponse('Failed to get course discussions');
        }
    }

    public function storeCourseDiscussion(Request $request)
    {
        try {
            $validated = $request->validate([
                'course_id' => 'nullable|exists:courses,id',
                'course_slug' => 'nullable|exists:courses,slug',
                'message' => 'required|string',
                'parent_id' => 'nullable|exists:course_discussions,id',
            ]);

            // Custom validation to ensure either course_id or course_slug is provided
            if (!$request->has('course_id') && !$request->has('course_slug')) {
                return ApiResponseService::validationError('Either course_id or course_slug is required.');
            }

            if ($request->has('course_id') && $request->has('course_slug')) {
                return ApiResponseService::validationError('Please provide either course_id or course_slug, not both.');
            }

            // Determine course_id from either course_id or course_slug
            $courseId = null;
            if ($request->has('course_id')) {
                $courseId = $request->course_id;
            } else {
                // Get course_id from course_slug
                $course = \App\Models\Course\Course::where('slug', $request->course_slug)->first();
                if (!$course) {
                    return ApiResponseService::errorResponse('Course not found with the provided slug.');
                }
                $courseId = $course->id;
            }

            if (!$this->userHasAccess(Auth::id(), $courseId)) {
                return ApiResponseService::errorResponse('You must purchase this course to comment.');
            }

            $discussion = CourseDiscussion::create([
                'status' => FeatureFlagService::isEnabled('comments_require_approval') ? 'pending' : 'approved',
                'user_id' => Auth::id(),
                'course_id' => $courseId,
                'message' => $validated['message'],
                'parent_id' => $validated['parent_id'] ?? null,
            ]);

            // Reload the discussion with relationships to match GET API format
            $discussion = CourseDiscussion::with(['user', 'replies.user'])->find($discussion->id);

            // Add time_ago for the discussion
            $discussion->time_ago = $discussion?->created_at->diffForHumans();

            // Add reply_count for the discussion
            $discussion->reply_count = $discussion?->replies->count();

            // Transform replies to add time_ago
            $discussion->replies = $discussion->replies->map(static function ($reply) {
                $reply->time_ago = $reply->created_at->diffForHumans();
                return $reply;
            });

            return ApiResponseService::successResponse('Discussion posted successfully', $discussion);
        } catch (\Throwable $th) {
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    // 🔒 Check order-based access
    private function userHasAccess($userId, $courseId): bool
    {
        return Order::where('user_id', $userId)
            ->where('status', 'completed') // or 'completed' based on your order system
            ->whereHas('orderCourses', static function ($q) use ($courseId): void {
                $q->where('course_id', $courseId);
            })
            ->exists();
    }
}
