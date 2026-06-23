<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Instructor;
use App\Models\Rating;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Admin Ratings API Controller
 *
 * Manages the full review approval workflow from the Next.js admin dashboard.
 *
 * Routes (all require admin Sanctum token):
 *   GET    /api/admin/ratings           → index()   — paginated list, all statuses, searchable
 *   PUT    /api/admin/ratings/{id}      → update()  — update status / rating / review text
 *   DELETE /api/admin/ratings/{id}      → destroy() — hard delete
 */
class RatingAdminApiController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/ratings
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return a paginated list of ALL ratings (pending + approved + rejected).
     *
     * Query params:
     *   - per_page  (int,    default 15)
     *   - page      (int,    default 1)
     *   - search    (string) — searches review text, user name, course title
     *   - status    (string) — optional: pending | approved | rejected
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'nullable|integer|min:1|max:100',
            'page'     => 'nullable|integer|min:1',
            'search'   => 'nullable|string|max:255',
            'status'   => 'nullable|in:pending,approved,rejected',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $perPage = (int) ($request->per_page ?? 15);
            $search  = $request->search;

            // ── Build base query ──────────────────────────────────────────
            // Use sub-queries for course/user joins so we never get an
            // "ambiguous column" error on the id / created_at columns.
            $query = Rating::query()
                ->select('ratings.*')                   // explicit select prevents column ambiguity
                ->with([
                    'user:id,name,email,profile',
                    'rateable',
                ])
                ->when($request->filled('status'), fn($q) => $q->where('ratings.status', $request->status))
                ->orderByDesc('ratings.created_at');

            // ── Search ────────────────────────────────────────────────────
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    // 1) Search inside the review text itself
                    $q->where('ratings.review', 'like', "%{$search}%")

                    // 2) Search by student name (using whereHas — no join needed)
                    ->orWhereHas('user', fn($uq) => $uq->where('users.name', 'like', "%{$search}%"))

                    // 3) Search by course title (uses EXISTS sub-query — no column clash)
                    ->orWhere(function ($sq) use ($search) {
                        $sq->where('ratings.rateable_type', Course::class)
                            ->whereExists(function ($exists) use ($search) {
                                $exists->selectRaw('1')
                                    ->from('courses')
                                    ->whereColumn('courses.id', 'ratings.rateable_id')
                                    ->where('courses.title', 'like', "%{$search}%")
                                    ->whereNull('courses.deleted_at');
                            });
                    });
                });
            }

            // ── Paginate & format ─────────────────────────────────────────
            $paginated = $query->paginate($perPage);

            $rows = $paginated->getCollection()->map(fn(Rating $r) => $this->formatRow($r));
            $paginated->setCollection($rows);

            return ApiResponseService::successResponse('Ratings retrieved successfully', $paginated);

        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Admin RatingAdminApiController -> index');
            return ApiResponseService::errorResponse('Failed to retrieve ratings.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/admin/ratings/{id}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update a rating's status, score, or review text.
     *
     * Payload (all sometimes|required so admin can update any subset):
     *   - status  (pending | approved | rejected)
     *   - rating  (1-5)
     *   - review  (string)
     *
     * When status is set to "approved" the review immediately becomes
     * visible on the public course page (getCourseReviews filters by status=approved).
     */
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
                $data['status']      = $request->status;
                $data['reviewed_by'] = Auth::id();
                $data['reviewed_at'] = now();
            }

            if ($request->has('rating')) {
                $data['rating'] = (int) $request->rating;
            }

            if ($request->has('review')) {
                $data['review'] = $request->review;
            }

            $ratingModel->update($data);

            // Notify student when status changes
            if ($request->has('status') && $ratingModel->user) {
                try {
                    $ratingModel->user->notify(
                        new \App\Notifications\ReviewStatusNotification($ratingModel, 'rating', $request->status)
                    );
                } catch (Throwable) {
                    // Non-fatal — don't fail the request if notification fails
                }
            }

            return ApiResponseService::successResponse(
                'Rating updated successfully.',
                $this->formatRow($ratingModel->fresh(['user', 'rateable']))
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ApiResponseService::errorResponse('Rating not found.', null, 404);
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Admin RatingAdminApiController -> update');
            return ApiResponseService::errorResponse('Failed to update rating.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/admin/ratings/{id}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Permanently delete a rating from the database.
     */
    public function destroy(int $id)
    {
        try {
            $rating = Rating::findOrFail($id);
            $rating->delete();

            return ApiResponseService::successResponse('Rating deleted successfully.');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ApiResponseService::errorResponse('Rating not found.', null, 404);
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'Admin RatingAdminApiController -> destroy');
            return ApiResponseService::errorResponse('Failed to delete rating.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Format a single Rating row for the frontend table.
     * Returns exactly the fields the Next.js dashboard expects.
     */
    private function formatRow(Rating $r): array
    {
        // Resolve course/instructor title from polymorphic relation
        $courseTitle = null;
        if ($r->rateable) {
            if ($r->rateable instanceof Course) {
                $courseTitle = $r->rateable->title ?? null;
            } elseif ($r->rateable instanceof Instructor) {
                // Instructor name via their user relation
                $courseTitle = optional($r->rateable->user)->name ?? 'Instructor #' . $r->rateable_id;
            } else {
                $courseTitle = $r->rateable->title ?? $r->rateable->name ?? null;
            }
        }

        return [
            'id'           => $r->id,
            'rating'       => $r->rating,
            'review'       => $r->review,
            'user_name'    => $r->user->name ?? 'Unknown',
            'user_email'   => $r->user->email ?? null,
            'user_avatar'  => $r->user->profile ?? null,
            'course_title' => $courseTitle,
            'rateable_type'=> $r->rateable_type,
            'rateable_id'  => $r->rateable_id,
            'status'       => $r->status,
            'reviewed_by'  => $r->reviewed_by,
            'reviewed_at'  => $r->reviewed_at?->toIso8601String(),
            'created_at'   => $r->created_at?->toIso8601String(),
            'updated_at'   => $r->updated_at?->toIso8601String(),
        ];
    }
}
