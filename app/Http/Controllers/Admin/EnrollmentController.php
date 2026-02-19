<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\OrderCourse;
use App\Models\User;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
{
    public function index(Request $request)
    {
        ResponseService::noPermissionThenRedirect('enrollments-list');
        $query = OrderCourse::with(['order.user', 'course.user'])->whereHas('order', static function ($q): void {
            $q->where('status', 'completed');
        })->orderBy('created_at', 'desc');

        // Apply filters
        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }

        if ($request->filled('instructor_id')) {
            $query->where(static function ($q) use ($request): void {
                // Check if instructor is the course owner
                $q
                    ->whereHas('course', static function ($courseQuery) use ($request): void {
                        $courseQuery->where('user_id', $request->instructor_id);
                    })
                    // OR check if instructor is in the course_instructors pivot table
                    ->orWhereHas('course.instructors', static function ($instructorQuery) use ($request): void {
                        $instructorQuery->where('user_id', $request->instructor_id);
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(static function ($q) use ($search): void {
                $q->whereHas('order.user', static function ($userQuery) use ($search): void {
                    $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('course', static function ($courseQuery) use ($search): void {
                    $courseQuery->where('title', 'like', "%{$search}%");
                });
            });
        }

        $enrollments = $query->paginate(15);

        // Get summary statistics
        $stats = [
            'total_enrollments' => OrderCourse::whereHas('order', static function ($q): void {
                $q->where('status', 'completed');
            })->count(),
            'today_enrollments' => OrderCourse::whereHas('order', static function ($q): void {
                $q->where('status', 'completed');
            })
                ->whereDate('created_at', today())
                ->count(),
            'monthly_enrollments' => OrderCourse::whereHas('order', static function ($q): void {
                $q->where('status', 'completed');
            })
                ->whereMonth('created_at', now()->month)
                ->count(),
            'active_students' => User::whereHas('orders.orderCourses', static function ($q): void {
                $q->whereHas('order', static function ($orderQuery): void {
                    $orderQuery->where('status', 'completed');
                });
            })
                ->distinct()
                ->count(),
        ];

        // Get courses and instructors for filters
        $courses = Course::select('id', 'title')->get();
        $instructors = User::role('instructor')->select('id', 'name')->get();

        return view('pages.admin.enrollments.index', compact('enrollments', 'stats', 'courses', 'instructors'), [
            'type_menu' => 'enrollments',
        ]);
    }

    public function show($id)
    {
        $enrollment = OrderCourse::with([
            'order.user.orders.orderCourses',
            'course.user',
            'course.chapters.lectures',
        ])->findOrFail($id);

        // Ensure required relationships are loaded
        if (!$enrollment->order || !$enrollment->order->user || !$enrollment->course) {
            return redirect()
                ->route('admin.enrollments.index')
                ->with('error', 'Enrollment data is incomplete. Missing order, user, or course information.');
        }

        return view('pages.admin.enrollments.show', compact('enrollment'), ['type_menu' => 'enrollments']);
    }

    public function getDashboardData()
    {
        $data = [
            'recent_enrollments' => OrderCourse::with(['order.user', 'course'])
                ->whereHas('order', static function ($q): void {
                    $q->where('status', 'completed');
                })
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
            'top_courses' => OrderCourse::select('course_id', DB::raw('count(*) as enrollments'))
                ->whereHas('order', static function ($q): void {
                    $q->where('status', 'completed');
                })
                ->with('course:id,title')
                ->groupBy('course_id')
                ->orderBy('enrollments', 'desc')
                ->limit(5)
                ->get(),
            'monthly_enrollments' => OrderCourse::whereHas('order', static function ($q): void {
                $q->where('status', 'completed');
            })
                ->whereMonth('created_at', now()->month)
                ->count(),
            'daily_enrollments' => OrderCourse::whereHas('order', static function ($q): void {
                $q->where('status', 'completed');
            })
                ->whereDate('created_at', today())
                ->count(),
        ];

        return response()->json($data);
    }
}
