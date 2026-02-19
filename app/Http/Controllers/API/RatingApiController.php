<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Order;
use App\Models\Rating;
use App\Services\ApiResponseService;
use App\Services\FeatureFlagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        try {
            $user = Auth::user();

            if (!$request->course_id && !$request->instructor_id) {
                return ApiResponseService::errorResponse('Either course_id or instructor_id is required.');
            }

            // --- Course review flow ---
            if ($request->course_id) {
                $rateableType = \App\Models\Course\Course::class;
                $rateableId = (int) $request->course_id;

                // Must have purchased this course
                $hasPurchased = Order::where('user_id', $user?->id)
                    ->whereIn('status', ['completed'])
                    ->whereHas('orderCourses', static function ($q) use ($rateableId): void {
                        $q->where('course_id', $rateableId);
                    })
                    ->exists();

                if (!$hasPurchased) {
                    return ApiResponseService::errorResponse('You can only review a course you have purchased.');
                }

                // --- Instructor review flow ---
            } else {
                $rateableType = \App\Models\Instructor::class;
                $rateableId = (int) $request->instructor_id;

                // 1) Instructor must be approved
                $instructor = \App\Models\Instructor::query()
                    ->where('id', $rateableId)
                    ->where('status', 'approved')
                    ->first();

                if (!$instructor) {
                    return ApiResponseService::errorResponse('You can only review approved instructors.');
                }

                // 2) OPTIONAL but recommended:
                // User must have purchased at least one course owned by this instructor's user_id
                // (courses.user_id == instructors.user_id)
                $ownerUserId = $instructor->user_id;

                $hasPurchasedFromOwner = Order::where('user_id', $user?->id)
                    ->whereIn('status', ['completed'])
                    ->whereHas('orderCourses.course', static function ($q) use ($ownerUserId): void {
                        $q->where('user_id', $ownerUserId);
                    })
                    ->exists();

                if (!$hasPurchasedFromOwner) {
                    return ApiResponseService::errorResponse(
                        'You can only review an instructor after purchasing at least one of their courses.',
                    );
                }
            }

            // Upsert (create or update) rating
            $attributes = [
                'user_id' => $user?->id,
                'rateable_type' => $rateableType,
                'rateable_id' => $rateableId,
            ];

            $values = [
                'rating' => (int) $request->rating,
                'review' => $request->review,
                'status' => FeatureFlagService::isEnabled('ratings_require_approval') ? 'pending' : 'approved',
            ];

            $rating = Rating::updateOrCreate($attributes, $values);

            return ApiResponseService::successResponse('Review saved successfully', [
                'rating' => $rating,
                'is_updated' => $rating->wasRecentlyCreated === false, // convenience flag
            ]);
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to add/update review');
            return ApiResponseService::errorResponse('Failed to save review.');
        }
    }

    public function updateRating(Request $request)
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

        try {
            $user = Auth::user();

            if (!$request->course_id && !$request->instructor_id) {
                return ApiResponseService::errorResponse('Either course_id or instructor_id is required.');
            }

            // Determine rateable type and ID
            if ($request->course_id) {
                $rateableType = \App\Models\Course\Course::class;
                $rateableId = (int) $request->course_id;
            } else {
                $rateableType = \App\Models\Instructor::class;
                $rateableId = (int) $request->instructor_id;
            }

            // Find existing rating
            $rating = Rating::where('user_id', $user?->id)
                ->where('rateable_type', $rateableType)
                ->where('rateable_id', $rateableId)
                ->first();

            if (!$rating) {
                return ApiResponseService::errorResponse('Review not found.');
            }

            // Update rating
            $rating->update([
                'rating' => (int) $request->rating,
                'review' => $request->review,
            ]);

            return ApiResponseService::successResponse('Review updated successfully', [
                'rating' => $rating,
            ]);
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
            'rating_id' => 'nullable|exists:ratings,id', // Optional: if rating_id is provided, use it directly
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();

            // If rating_id is provided, use it directly
            if ($request->filled('rating_id')) {
                $rating = Rating::where('id', $request->rating_id)->where('user_id', $user?->id)->first();

                if (!$rating) {
                    return ApiResponseService::errorResponse(
                        'Review not found or you do not have permission to delete it.',
                    );
                }

                $rating->delete();
                return ApiResponseService::successResponse('Review deleted successfully');
            }

            // Otherwise, find by course_id or instructor_id
            if (!$request->course_id && !$request->instructor_id) {
                return ApiResponseService::errorResponse('Either course_id, instructor_id, or rating_id is required.');
            }

            // Determine rateable type and ID
            if ($request->course_id) {
                $rateableType = \App\Models\Course\Course::class;
                $rateableId = (int) $request->course_id;
            } else {
                $rateableType = \App\Models\Instructor::class;
                $rateableId = (int) $request->instructor_id;
            }

            // Find and delete rating
            $rating = Rating::where('user_id', $user?->id)
                ->where('rateable_type', $rateableType)
                ->where('rateable_id', $rateableId)
                ->first();

            if (!$rating) {
                return ApiResponseService::errorResponse('Review not found.');
            }

            $rating->delete();

            return ApiResponseService::successResponse('Review deleted successfully');
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to delete review');
            return ApiResponseService::errorResponse('Failed to delete review.');
        }
    }
}
