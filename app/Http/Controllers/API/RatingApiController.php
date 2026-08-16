<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Instructor;
use App\Models\Rating;
use App\Models\User;
use App\Notifications\AdminNewReviewNotification;
use App\Services\ApiResponseService;
use App\Services\ContentAccessService;
use App\Services\FeatureFlagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

class RatingApiController extends Controller
{
    public function addRating(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'nullable|exists:courses,id',
            'instructor_id' => 'nullable|exists:instructors,id',
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        if ($request->filled('course_id') && $request->filled('instructor_id')) {
            return ApiResponseService::errorResponse('Both course_id and instructor_id cannot be provided together.');
        }

        if (!$request->filled('course_id') && !$request->filled('instructor_id')) {
            return ApiResponseService::errorResponse('Either course_id or instructor_id is required.');
        }

        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthenticated.', null, 401);
            }

            // --- Course review flow ---
            if ($request->filled('course_id')) {
                $rateableType = Course::class;
                $rateableId = (int) $request->course_id;

                $course = Course::where('id', $rateableId)
                    ->where('is_active', 1)
                    ->where('status', 'publish')
                    ->where('approval_status', 'approved')
                    ->first();

                if (!$course) {
                    return ApiResponseService::validationError('Course not found or not currently available for review.');
                }

                $canAccessCourse = app(ContentAccessService::class)->canAccessCourse($user, $course);

                if (!$canAccessCourse) {
                    return ApiResponseService::errorResponse(
                        'You can only review a course after enrolling in or purchasing it.',
                        null,
                        403,
                    );
                }

                // --- Instructor review flow ---
            } else {
                $rateableType = Instructor::class;
                $rateableId = (int) $request->instructor_id;

                // 1) Instructor must be approved
                $instructor = Instructor::query()
                    ->where('id', $rateableId)
                    ->where('status', 'approved')
                    ->first();

                if (!$instructor) {
                    return ApiResponseService::errorResponse('Instructor not found or not available for review.');
                }

                // 2) Check if user is a legitimate learner of this instructor:
                // User must have access to at least one active course owned by this instructor (via purchase, subscription, or free enrollment)
                $ownerUserId = $instructor->user_id;
                $instructorCourses = Course::where('user_id', $ownerUserId)
                    ->where('is_active', 1)
                    ->where('status', 'publish')
                    ->where('approval_status', 'approved')
                    ->get();

                $contentAccessService = app(ContentAccessService::class);
                $hasAccessToAny = false;
                foreach ($instructorCourses as $c) {
                    if ($contentAccessService->canAccessCourse($user, $c)) {
                        $hasAccessToAny = true;
                        break;
                    }
                }

                if (!$hasAccessToAny) {
                    return ApiResponseService::errorResponse(
                        'You can only review an instructor after enrolling in or purchasing at least one of their courses.',
                        null,
                        403,
                    );
                }
            }

            // Determine initial moderation status
            $applyApproval = app(FeatureFlagService::class)->isEnabled('comments_require_approval');
            $status = $applyApproval ? 'pending' : 'approved';

            // Clean review text
            $cleanReview = $request->filled('review') ? strip_tags(trim((string) $request->review)) : null;

            // Upsert (create or update) rating
            $attributes = [
                'user_id' => $user->id,
                'rateable_type' => $rateableType,
                'rateable_id' => $rateableId,
            ];

            $values = [
                'rating' => (int) $request->rating,
                'review' => $cleanReview,
                'status' => $status,
            ];

            $rating = Rating::updateOrCreate($attributes, $values);

            // Notify admins when review is pending moderation
            if ($rating->status === 'pending') {
                $admins = User::role(['Super Admin', 'Admin'])->get();
                if ($admins->isNotEmpty()) {
                    Notification::send($admins, new AdminNewReviewNotification($rating, 'rating'));
                }
            }

            $successMsg = $rating->status === 'pending'
                ? 'Review saved successfully and is pending admin approval.'
                : 'Review saved successfully.';

            return ApiResponseService::successResponse($successMsg, [
                'rating' => $rating,
                'is_updated' => $rating->wasRecentlyCreated === false,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to add/update review');
            return ApiResponseService::errorResponse('Failed to save review.');
        }
    }

    public function updateRating(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rating_id' => 'nullable|exists:ratings,id',
            'course_id' => 'nullable|exists:courses,id',
            'instructor_id' => 'nullable|exists:instructors,id',
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        if ($request->filled('course_id') && $request->filled('instructor_id')) {
            return ApiResponseService::errorResponse('Both course_id and instructor_id cannot be provided together.');
        }

        if (!$request->filled('rating_id') && !$request->filled('course_id') && !$request->filled('instructor_id')) {
            return ApiResponseService::errorResponse('Either rating_id, course_id, or instructor_id is required.');
        }

        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthenticated.', null, 401);
            }

            // Find existing rating strictly scoped to authenticated user
            if ($request->filled('rating_id')) {
                $rating = Rating::where('id', $request->rating_id)
                    ->where('user_id', $user->id)
                    ->first();
            } else {
                $rateableType = $request->filled('course_id') ? Course::class : Instructor::class;
                $rateableId = (int) ($request->course_id ?? $request->instructor_id);

                $rating = Rating::where('user_id', $user->id)
                    ->where('rateable_type', $rateableType)
                    ->where('rateable_id', $rateableId)
                    ->first();
            }

            if (!$rating) {
                return ApiResponseService::errorResponse('Review not found or you do not have permission to edit it.', null, 404);
            }

            // Re-evaluate moderation status for edits
            $applyApproval = app(FeatureFlagService::class)->isEnabled('comments_require_approval');
            $status = $applyApproval ? 'pending' : 'approved';

            $cleanReview = $request->filled('review') ? strip_tags(trim((string) $request->review)) : null;

            // Update rating
            $rating->update([
                'rating' => (int) $request->rating,
                'review' => $cleanReview,
                'status' => $status,
            ]);

            // Notify admins if edited review entered pending moderation
            if ($rating->status === 'pending') {
                $admins = User::role(['Super Admin', 'Admin'])->get();
                if ($admins->isNotEmpty()) {
                    Notification::send($admins, new AdminNewReviewNotification($rating, 'rating'));
                }
            }

            $successMsg = $rating->status === 'pending'
                ? 'Review updated successfully and is pending admin approval.'
                : 'Review updated successfully.';

            return ApiResponseService::successResponse($successMsg, [
                'rating' => $rating,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to update review');
            return ApiResponseService::errorResponse('Failed to update review.');
        }
    }

    public function deleteRating(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'nullable|exists:courses,id',
            'instructor_id' => 'nullable|exists:instructors,id',
            'rating_id' => 'nullable|exists:ratings,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        if ($request->filled('course_id') && $request->filled('instructor_id')) {
            return ApiResponseService::errorResponse('Both course_id and instructor_id cannot be provided together.');
        }

        if (!$request->filled('rating_id') && !$request->filled('course_id') && !$request->filled('instructor_id')) {
            return ApiResponseService::errorResponse('Either course_id, instructor_id, or rating_id is required.');
        }

        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthenticated.', null, 401);
            }

            // Find rating strictly scoped to authenticated user
            if ($request->filled('rating_id')) {
                $rating = Rating::where('id', $request->rating_id)
                    ->where('user_id', $user->id)
                    ->first();
            } else {
                $rateableType = $request->filled('course_id') ? Course::class : Instructor::class;
                $rateableId = (int) ($request->course_id ?? $request->instructor_id);

                $rating = Rating::where('user_id', $user->id)
                    ->where('rateable_type', $rateableType)
                    ->where('rateable_id', $rateableId)
                    ->first();
            }

            if (!$rating) {
                return ApiResponseService::errorResponse(
                    'Review not found or you do not have permission to delete it.',
                    null,
                    404,
                );
            }

            $rating->delete();

            return ApiResponseService::successResponse('Review deleted successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to delete review');
            return ApiResponseService::errorResponse('Failed to delete review.');
        }
    }
}
