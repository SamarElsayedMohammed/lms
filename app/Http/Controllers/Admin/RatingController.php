<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rating;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RatingController extends Controller
{
    /**
     * Display ratings listing with filters
     */
    public function index(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['ratings-list', 'approve_ratings']);
        $query = Rating::with(['user:id,name,email,profile', 'rateable']);

        // Filter by rating type (course or instructor)
        if ($request->has('type') && $request->type != '') {
            if ($request->type === 'course') {
                $query->where('rateable_type', \App\Models\Course\Course::class);
            } elseif ($request->type === 'instructor') {
                $query->where('rateable_type', \App\Models\Instructor::class);
            }
        }

        // Filter by rating value
        if ($request->has('rating') && $request->rating != '') {
            $query->where('rating', $request->rating);
        }

        // Filter by date range
        if ($request->has('date_from') && $request->date_from != '') {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && $request->date_to != '') {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search functionality
        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(static function ($q) use ($search): void {
                $q
                    ->whereHas('user', static function ($userQuery) use ($search): void {
                        $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhere('review', 'like', "%{$search}%")
                    ->orWhere(static function ($subQuery) use ($search): void {
                        // Search in courses using direct table join
                        $subQuery
                            ->where('rateable_type', \App\Models\Course\Course::class)
                            ->whereExists(static function ($existsQuery) use ($search): void {
                                $existsQuery
                                    ->select(DB::raw(1))
                                    ->from('courses')
                                    ->whereColumn('courses.id', 'ratings.rateable_id')
                                    ->where('courses.title', 'like', "%{$search}%")
                                    ->whereNull('courses.deleted_at');
                            });
                    })
                    ->orWhere(static function ($subQuery) use ($search): void {
                        // Search in instructors using direct table joins
                        $subQuery
                            ->where('rateable_type', \App\Models\Instructor::class)
                            ->whereExists(static function ($existsQuery) use ($search): void {
                                $existsQuery
                                    ->select(DB::raw(1))
                                    ->from('instructors')
                                    ->join('users', 'instructors.user_id', '=', 'users.id')
                                    ->whereColumn('instructors.id', 'ratings.rateable_id')
                                    ->where('users.name', 'like', "%{$search}%")
                                    ->whereNull('instructors.deleted_at')
                                    ->whereNull('users.deleted_at');
                            });
                    });
            });
        }

        $ratings = $query->orderBy('created_at', 'desc')->paginate(15);

        // Get statistics
        $stats = $this->getStats();

        return view('admin.ratings.index', [
            'type_menu' => 'ratings',
            'ratings' => $ratings,
            'stats' => $stats,
            'filters' => [
                'type' => $request->type,
                'rating' => $request->rating,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'search' => $request->search,
            ],
        ]);
    }

    /**
     * Show rating details
     */
    public function show($id)
    {
        ResponseService::noAnyPermissionThenRedirect(['ratings-list', 'approve_ratings']);
        $rating = Rating::with(['user:id,name,email,profile,created_at'])->findOrFail($id);

        // Load rateable relationship safely
        if ($rating->rateable_type && $rating->rateable_id) {
            try {
                $rating->load('rateable');
            } catch (\Exception) {
                // Handle case where rateable no longer exists
                $rating->rateable = null;
            }
        }

        return view('admin.ratings.show', [
            'type_menu' => 'ratings',
            'rating' => $rating,
        ]);
    }

    /**
     * Delete a rating
     */
    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('ratings-delete');
        try {
            $rating = Rating::findOrFail($id);
            $rating->delete();

            return ResponseService::successResponse('Rating deleted successfully');
        } catch (\Exception $e) {
            return ResponseService::errorResponse('Failed to delete rating: ' . $e->getMessage());
        }
    }

    /**
     * Get rating statistics
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $courseType = \App\Models\Course\Course::class;
        $instructorType = \App\Models\Instructor::class;

        // Get all stats in a single query
        $aggregates = Rating::selectRaw('
            COUNT(*) as total_count,
            AVG(rating) as total_avg,
            SUM(CASE WHEN rateable_type = ? THEN 1 ELSE 0 END) as course_count,
            AVG(CASE WHEN rateable_type = ? THEN rating ELSE NULL END) as course_avg,
            SUM(CASE WHEN rateable_type = ? THEN 1 ELSE 0 END) as instructor_count,
            AVG(CASE WHEN rateable_type = ? THEN rating ELSE NULL END) as instructor_avg,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as total_5,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as total_4,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as total_3,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as total_2,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as total_1,
            SUM(CASE WHEN rateable_type = ? AND rating = 5 THEN 1 ELSE 0 END) as course_5,
            SUM(CASE WHEN rateable_type = ? AND rating = 4 THEN 1 ELSE 0 END) as course_4,
            SUM(CASE WHEN rateable_type = ? AND rating = 3 THEN 1 ELSE 0 END) as course_3,
            SUM(CASE WHEN rateable_type = ? AND rating = 2 THEN 1 ELSE 0 END) as course_2,
            SUM(CASE WHEN rateable_type = ? AND rating = 1 THEN 1 ELSE 0 END) as course_1,
            SUM(CASE WHEN rateable_type = ? AND rating = 5 THEN 1 ELSE 0 END) as instructor_5,
            SUM(CASE WHEN rateable_type = ? AND rating = 4 THEN 1 ELSE 0 END) as instructor_4,
            SUM(CASE WHEN rateable_type = ? AND rating = 3 THEN 1 ELSE 0 END) as instructor_3,
            SUM(CASE WHEN rateable_type = ? AND rating = 2 THEN 1 ELSE 0 END) as instructor_2,
            SUM(CASE WHEN rateable_type = ? AND rating = 1 THEN 1 ELSE 0 END) as instructor_1
        ', [
            $courseType,
            $courseType,
            $instructorType,
            $instructorType,
            $courseType,
            $courseType,
            $courseType,
            $courseType,
            $courseType,
            $instructorType,
            $instructorType,
            $instructorType,
            $instructorType,
            $instructorType,
        ])->first();

        return [
            'total' => [
                'count' => (int) $aggregates->total_count,
                'average' => round($aggregates->total_avg ?? 0, 1),
                'breakdown' => [
                    5 => (int) $aggregates->total_5,
                    4 => (int) $aggregates->total_4,
                    3 => (int) $aggregates->total_3,
                    2 => (int) $aggregates->total_2,
                    1 => (int) $aggregates->total_1,
                ],
            ],
            'courses' => [
                'count' => (int) $aggregates->course_count,
                'average' => round($aggregates->course_avg ?? 0, 1),
                'breakdown' => [
                    5 => (int) $aggregates->course_5,
                    4 => (int) $aggregates->course_4,
                    3 => (int) $aggregates->course_3,
                    2 => (int) $aggregates->course_2,
                    1 => (int) $aggregates->course_1,
                ],
            ],
            'instructors' => [
                'count' => (int) $aggregates->instructor_count,
                'average' => round($aggregates->instructor_avg ?? 0, 1),
                'breakdown' => [
                    5 => (int) $aggregates->instructor_5,
                    4 => (int) $aggregates->instructor_4,
                    3 => (int) $aggregates->instructor_3,
                    2 => (int) $aggregates->instructor_2,
                    1 => (int) $aggregates->instructor_1,
                ],
            ],
            'monthly_ratings' => $this->getMonthlyRatings(),
        ];
    }

    /**
     * Get monthly ratings data for charts
     */
    private function getMonthlyRatings()
    {
        return Rating::selectRaw('
                YEAR(created_at) as year,
                MONTH(created_at) as month,
                COUNT(*) as count,
                AVG(rating) as avg_rating
            ')
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();
    }

    /**
     * Get ratings data for dashboard
     */
    public function getDashboardData()
    {
        $stats = $this->getStats();

        return response()->json([
            'status' => true,
            'data' => $stats,
        ]);
    }
}
