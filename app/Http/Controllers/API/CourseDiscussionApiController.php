<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseDiscussion;
use App\Models\Order;
use App\Models\Subscription;
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

            // Determine course from either course_id or course_slug with canonical public visibility
            $courseQuery = \App\Models\Course\Course::where('is_active', 1)
                ->where('status', 'publish')
                ->where('approval_status', 'approved');

            if ($request->has('course_id')) {
                $course = $courseQuery->where('id', $request->course_id)->first();
            } else {
                $course = $courseQuery->where('slug', $request->course_slug)->first();
            }

            if (!$course) {
                return ApiResponseService::validationError('Course not found or is not currently published.');
            }
            $courseId = $course->id;

            $applyApproval = app(FeatureFlagService::class)->isEnabled('comments_require_approval');
            $userId = Auth::id();

            // Get total count of all discussions for this course (without search filter)
            $baseQuery = CourseDiscussion::where('course_id', $courseId)->whereNull('parent_id');
            $allDiscussionCount = $applyApproval
                ? $this->visibleDiscussions($baseQuery, $userId)->count()
                : $baseQuery->count();

            // Build search closure
            $searchClosure = function ($query) use ($searchTerm, $applyApproval, $userId): void {
                $query->where(function ($q) use ($searchTerm, $applyApproval, $userId): void {
                    $q->where('message', 'LIKE', "%{$searchTerm}%")
                        ->orWhereHas('replies', function ($replyQuery) use ($searchTerm, $applyApproval, $userId): void {
                            $replyQuery->where('message', 'LIKE', "%{$searchTerm}%");
                            if ($applyApproval) {
                                $this->visibleDiscussions($replyQuery, $userId);
                            }
                        });
                });
            };

            // Get filtered count if search is applied
            $filteredDiscussionCount = null;
            if ($searchTerm) {
                $searchQuery = CourseDiscussion::where('course_id', $courseId)
                    ->whereNull('parent_id');
                $searchClosure($searchQuery);
                $filteredDiscussionCount = $applyApproval
                    ? $this->visibleDiscussions($searchQuery, $userId)->count()
                    : $searchQuery->count();
            }

            // Build query for discussions
            $repliesConstraint = $applyApproval
                ? fn($q) => $this->visibleDiscussions($q->with('user:id,name,profile'), $userId)
                : fn($q) => $q->with('user:id,name,profile');
            $discussionsQuery = CourseDiscussion::with(['user:id,name,profile', 'replies' => $repliesConstraint])
                ->where('course_id', $courseId)
                ->whereNull('parent_id');
            if ($applyApproval) {
                $this->visibleDiscussions($discussionsQuery, $userId);
            }

            // Apply search filter if search term is provided
            if ($searchTerm) {
                $searchClosure($discussionsQuery);
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get course discussions');
            return ApiResponseService::errorResponse('Failed to get course discussions');
        }
    }

    private function visibleDiscussions($query, $userId)
    {
        return $query->where(static function ($statusQuery) use ($userId): void {
            $statusQuery->approved();

            if ($userId !== null) {
                $statusQuery->orWhere(static function ($ownQuery) use ($userId): void {
                    $ownQuery->pending()->where('user_id', $userId);
                });
            }
        });
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

            // Determine course with canonical public visibility
            $courseQuery = \App\Models\Course\Course::where('is_active', 1)
                ->where('status', 'publish')
                ->where('approval_status', 'approved');

            if ($request->has('course_id')) {
                $course = $courseQuery->where('id', $request->course_id)->first();
            } else {
                $course = $courseQuery->where('slug', $request->course_slug)->first();
            }

            if (!$course) {
                return ApiResponseService::errorResponse('Course not found or is not currently published.');
            }
            $courseId = $course->id;

            if (!$this->userHasAccess(Auth::id(), $course)) {
                return ApiResponseService::forbidden('You must have active access to this course to comment.');
            }

            // Validate parent_id if replying to a thread
            $parentId = $validated['parent_id'] ?? null;
            if ($parentId !== null) {
                $parent = CourseDiscussion::find($parentId);
                if (!$parent || (int) $parent->course_id !== (int) $courseId) {
                    return ApiResponseService::validationError('Parent discussion does not belong to this course.');
                }
                if ($parent->parent_id !== null) {
                    return ApiResponseService::validationError('Nested replies beyond one level are not supported.');
                }
            }

            $discussion = CourseDiscussion::create([
                'status' => 'pending', // Forced pending per moderation rule
                'user_id' => Auth::id(),
                'course_id' => $courseId,
                'message' => trim($validated['message']),
                'parent_id' => $parentId,
            ]);

            try {
                $admins = \App\Models\User::whereHas("roles", static function ($query): void {
                    $query->whereIn("name", ["Super Admin", "Staff", "Supervisor"]);
                })->get();

                if ($admins->isNotEmpty()) {
                    \Illuminate\Support\Facades\Notification::send(
                        $admins,
                        new \App\Notifications\AdminNewReviewNotification($discussion, "comment")
                    );
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to notify admins about new discussion", [
                    "discussion_id" => $discussion->id,
                    "error" => $e->getMessage(),
                ]);
            }

            // Reload the discussion with relationships to match GET API format
            $discussion = CourseDiscussion::with(['user:id,name,profile', 'replies.user:id,name,profile'])->find($discussion->id);

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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    private function userHasAccess($userId, $course): bool
    {
        if (!$userId || !$course) {
            return false;
        }

        $user = \App\Models\User::find($userId);
        if (!$user) {
            return false;
        }

        return app(\App\Services\ContentAccessService::class)->canAccessCourse($user, $course);
    }
}
