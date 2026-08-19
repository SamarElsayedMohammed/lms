<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Instructor;
use App\Models\Rating;
use App\Notifications\ReviewStatusNotification;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Admin Ratings API Controller
 *
 * GET    /api/admin/ratings           → index()
 * PUT    /api/admin/ratings/{id}      → update()
 * DELETE /api/admin/ratings/{id}      → destroy()
 */
class RatingAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->ensureRatingsAdmin();
            return $next($request);
        });
    }

    /**
     * Super Admin | Admin | Supervisor | Staff — same set as other admin JSON routes.
     */
    private function ensureRatingsAdmin(): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->unauthorized('Unauthenticated');
        }

        $adminRoles = ['Super Admin', 'Admin', 'Supervisor', 'Staff'];
        if (!$user->hasAnyRole($adminRoles, 'web') && !$user->hasAnyRole($adminRoles)) {
            $this->unauthorized('Admin access required');
        }
    }

    /**
     * Paginated list of all ratings (pending + approved + rejected).
     *
     * Query: per_page, page, search, status, type (course|instructor), rating (1-5), course_id
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|in:pending,approved,rejected',
            'type' => 'nullable|in:course,instructor',
            'rating' => 'nullable|integer|min:1|max:5',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $perPage = (int) ($request->per_page ?? 15);

            $query = Rating::query()
                ->select('ratings.*')
                ->with([
                    'user:id,name,profile',
                    'rateable' => function ($morphTo): void {
                        $morphTo->morphWith([
                            Instructor::class => ['user:id,name'],
                        ]);
                    },
                ])
                ->when($request->filled('status'), fn ($q) => $q->where('ratings.status', $request->status))
                ->when($request->filled('rating'), fn ($q) => $q->where('ratings.rating', (int) $request->rating))
                ->when($request->input('type') === 'course', fn ($q) => $q->where('ratings.rateable_type', Course::class))
                ->when($request->input('type') === 'instructor', fn ($q) => $q->where('ratings.rateable_type', Instructor::class))
                ->when($request->filled('course_id'), function ($q) use ($request): void {
                    $q->where('ratings.rateable_type', Course::class)
                        ->where('ratings.rateable_id', (int) $request->course_id);
                })
                ->orderByDesc('ratings.created_at');

            if ($request->filled('search')) {
                $search = (string) $request->search;
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $query->where(function ($q) use ($like): void {
                    $q->where('ratings.review', 'like', $like)
                        ->orWhereHas('user', fn ($uq) => $uq->where('users.name', 'like', $like))
                        ->orWhere(function ($sq) use ($like): void {
                            $sq->where('ratings.rateable_type', Course::class)
                                ->whereExists(function ($exists) use ($like): void {
                                    $exists->selectRaw('1')
                                        ->from('courses')
                                        ->whereColumn('courses.id', 'ratings.rateable_id')
                                        ->where('courses.title', 'like', $like)
                                        ->whereNull('courses.deleted_at');
                                });
                        })
                        ->orWhere(function ($sq) use ($like): void {
                            $sq->where('ratings.rateable_type', Instructor::class)
                                ->whereExists(function ($exists) use ($like): void {
                                    $exists->selectRaw('1')
                                        ->from('instructors')
                                        ->join('users', 'instructors.user_id', '=', 'users.id')
                                        ->whereColumn('instructors.id', 'ratings.rateable_id')
                                        ->where('users.name', 'like', $like)
                                        ->whereNull('instructors.deleted_at')
                                        ->whereNull('users.deleted_at');
                                });
                        });
                });
            }

            $paginated = $query->paginate($perPage);
            $paginated->setCollection(
                $paginated->getCollection()->map(fn (Rating $r) => $r->toAdminArray())
            );

            return ApiResponseService::successResponse('Ratings retrieved successfully', $paginated);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Admin RatingAdminApiController -> index');
            return ApiResponseService::errorResponse('Failed to retrieve ratings.');
        }
    }

    public function update(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|required|in:pending,approved,rejected',
            'rating' => 'sometimes|required|integer|min:1|max:5',
            'review' => 'sometimes|nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $ratingModel = Rating::findOrFail($id);
            $data = [];

            if ($request->has('status')) {
                $data['status'] = $request->status;
                $data['reviewed_by'] = Auth::id();
                $data['reviewed_at'] = now();
            }

            if ($request->has('rating')) {
                $data['rating'] = (int) $request->rating;
            }

            if ($request->has('review')) {
                $data['review'] = $request->filled('review')
                    ? strip_tags(trim((string) $request->review))
                    : null;
            }

            $ratingModel->update($data);

            if ($request->has('status') && $ratingModel->user) {
                try {
                    $ratingModel->user->notify(
                        new ReviewStatusNotification($ratingModel, 'rating', $request->status)
                    );
                } catch (Throwable) {
                    // Non-fatal
                }
            }

            return ApiResponseService::successResponse(
                'Rating updated successfully.',
                $ratingModel->fresh(['user', 'rateable'])?->toAdminArray()
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ApiResponseService::errorResponse('Rating not found.', null, 404);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Admin RatingAdminApiController -> update');
            return ApiResponseService::errorResponse('Failed to update rating.');
        }
    }

    public function destroy(int $id)
    {
        try {
            $rating = Rating::findOrFail($id);
            $rating->delete();

            return ApiResponseService::successResponse('Rating deleted successfully.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ApiResponseService::errorResponse('Rating not found.', null, 404);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Admin RatingAdminApiController -> destroy');
            return ApiResponseService::errorResponse('Failed to delete rating.');
        }
    }
}
