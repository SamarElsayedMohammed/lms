<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\UserCourseChapterTrack;
use App\Models\Course\UserCourseTrack;
use App\Models\OrderCourse;
use App\Models\User;
use App\Models\UserCurriculumTracking;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrackingController extends Controller
{
    public function index(Request $request)
    {
        ResponseService::noPermissionThenRedirect('tracking-list');
        // Get unique user-course combinations from user_curriculum_trackings
        $baseQuery = UserCurriculumTracking::select('user_curriculum_trackings.user_id', 'course_chapters.course_id')
            ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->join('users', 'user_curriculum_trackings.user_id', '=', 'users.id')
            ->join('courses', 'course_chapters.course_id', '=', 'courses.id')
            ->whereHas('user.orders', static function ($q): void {
                $q->where('status', 'completed');
            })
            ->groupBy('user_curriculum_trackings.user_id', 'course_chapters.course_id');

        // Apply filters
        if ($request->filled('course_id')) {
            $baseQuery->where('course_chapters.course_id', $request->course_id);
        }

        if ($request->filled('instructor_id')) {
            $baseQuery->where('courses.user_id', $request->instructor_id);
        }

        if ($request->filled('date_from')) {
            $baseQuery->whereDate('user_curriculum_trackings.created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $baseQuery->whereDate('user_curriculum_trackings.created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $baseQuery->where(static function ($q) use ($search): void {
                $q
                    ->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('courses.title', 'like', "%{$search}%");
            });
        }

        // Get user-course pairs
        $userCoursePairs = $baseQuery->get();

        // Build tracking data with progress calculation
        $trackingsData = [];
        foreach ($userCoursePairs as $pair) {
            $userId = $pair->user_id;
            $courseId = $pair->course_id;

            // Get course and user
            $course = Course::with('user')->find($courseId);
            $user = User::find($userId);

            if (!$course || !$user) {
                continue;
            }

            // Calculate progress from curriculum trackings
            $progressData = $this->calculateProgressFromCurriculum($userId, $courseId);

            // Apply progress status filter
            if ($request->filled('progress_status')) {
                $statusMatch = false;
                switch ($request->progress_status) {
                    case 'not_started':
                        $statusMatch = $progressData['status'] === 'not_started';
                        break;
                    case 'in_progress':
                        $statusMatch = $progressData['status'] === 'in_progress';
                        break;
                    case 'completed':
                        $statusMatch = $progressData['status'] === 'completed';
                        break;
                }
                if (!$statusMatch) {
                    continue;
                }
            }

            $trackingsData[] = (object) [
                'id' => $userId . '_' . $courseId,
                'user' => $user,
                'course' => $course,
                'progress_percentage' => $progressData['progress_percentage'],
                'status' => $progressData['status'],
                'created_at' => $progressData['first_tracking_date'],
            ];
        }

        // Convert to collection and paginate manually
        $trackings = collect($trackingsData);
        $perPage = 15;
        $currentPage = $request->get('page', 1);
        $items = $trackings->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $trackings = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $trackings->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        // Get summary statistics based on curriculum tracking
        $stats = $this->getStatisticsFromCurriculum();

        // Get courses and instructors for filters
        $courses = Course::select('id', 'title')->get();
        $instructors = User::whereHas('roles', static function ($q): void {
            $q->where('name', 'instructor');
        })->select('id', 'name')->get();

        return view('pages.admin.tracking.index', compact('trackings', 'stats', 'courses', 'instructors'), [
            'type_menu' => 'tracking',
        ]);
    }

    public function show($id)
    {
        ResponseService::noPermissionThenRedirect('tracking-list');
        // Parse ID format: userId_courseId
        $parts = explode('_', (string) $id);
        if (count($parts) !== 2) {
            return redirect()
                ->route('admin.tracking.index')
                ->with('error', 'Invalid tracking ID format. Expected format: userId_courseId');
        }

        $userId = trim($parts[0]);
        $courseId = trim($parts[1]);

        // Validate that both IDs are numeric and greater than 0
        if (!is_numeric($userId) || !is_numeric($courseId) || (int) $userId <= 0 || (int) $courseId <= 0) {
            return redirect()
                ->route('admin.tracking.index')
                ->with('error', 'Invalid tracking ID format. User ID and Course ID must be valid numbers.');
        }

        $userId = (int) $userId;
        $courseId = (int) $courseId;

        // Get user and course
        $user = User::findOrFail($userId);
        $course = Course::with('user')->findOrFail($courseId);

        // Create a tracking object for compatibility with view
        $tracking = (object) [
            'id' => $id,
            'user' => $user,
            'course' => $course,
            'status' => 'not_started', // Will be updated from progress data
            'created_at' => now(),
            'completed_at' => null,
        ];

        // Get detailed progress data from curriculum tracking
        $progressData = $this->getDetailedProgressFromCurriculum($userId, $courseId);

        // Update tracking status from progress data
        $tracking->status = $progressData['status'];
        $tracking->created_at = $progressData['first_tracking_date'];
        if ($progressData['status'] === 'completed' && isset($progressData['completed_at'])) {
            $tracking->completed_at = $progressData['completed_at'];
        }

        return view('pages.admin.tracking.show', compact('tracking', 'progressData'), ['type_menu' => 'tracking']);
    }

    public function updateProgress(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:started,in_progress,completed',
        ]);

        $tracking = UserCourseTrack::findOrFail($id);

        $updateData = ['status' => $request->status];

        if ($request->status === 'completed') {
            $updateData['completed_at'] = now();
        }

        $tracking->update($updateData);

        return redirect()->back()->with('success', 'Progress updated successfully.');
    }

    public function getDashboardData()
    {
        $data = [
            'progress_distribution' => OrderCourse::select(DB::raw(
                'CASE
                    WHEN progress = 0 THEN "Not Started"
                    WHEN progress > 0 AND progress < 100 THEN "In Progress"
                    WHEN progress = 100 THEN "Completed"
                END as status',
            ), DB::raw('COUNT(*) as count'))
                ->whereHas('order', static function ($q): void {
                    $q->where('status', 'completed');
                })
                ->groupBy('status')
                ->pluck('count', 'status'),
            'top_performing_courses' => OrderCourse::select('course_id', DB::raw('AVG(progress) as avg_progress'))
                ->whereHas('order', static function ($q): void {
                    $q->where('status', 'completed');
                })
                ->with('course:id,title')
                ->groupBy('course_id')
                ->orderBy('avg_progress', 'desc')
                ->limit(5)
                ->get(),
            'recent_activity' => OrderCourse::with(['order.user', 'course'])
                ->whereHas('order', static function ($q): void {
                    $q->where('status', 'completed');
                })
                ->where('progress', '>', 0)
                ->orderBy('updated_at', 'desc')
                ->limit(10)
                ->get(),
        ];

        return response()->json($data);
    }

    /**
     * Get detailed progress from curriculum tracking
     */
    private function getDetailedProgressFromCurriculum($userId, $courseId)
    {
        // Get course with chapters and all curriculum items
        $course = Course::with([
            'chapters.lectures',
            'chapters.quizzes',
            'chapters.assignments',
            'chapters.resources',
        ])->findOrFail($courseId);

        $chapters = $course->chapters;
        $totalChapters = $chapters->count();

        // Count total curriculum items (lectures, quizzes, assignments, resources)
        $totalItems = 0;
        $totalLectures = 0;
        foreach ($chapters as $chapter) {
            $totalItems += $chapter->lectures->count();
            $totalItems += $chapter->quizzes->count();
            $totalItems += $chapter->assignments->count();
            $totalItems += $chapter->resources->count();
            $totalLectures += $chapter->lectures->count();
        }

        // Get all curriculum trackings for this user and course
        $curriculumTrackings = UserCurriculumTracking::where('user_id', $userId)->whereIn(
            'course_chapter_id',
            $chapters->pluck('id'),
        )->get();

        // Get first tracking date
        $firstTracking = $curriculumTrackings->sortBy('created_at')->first();

        // Get last completed date
        $lastCompleted = $curriculumTrackings->where('status', 'completed')->sortByDesc('completed_at')->first();

        // Count completed items
        $completedItems = $curriculumTrackings->where('status', 'completed')->count();

        // Calculate overall progress percentage
        $progressPercentage = $totalItems > 0 ? round(($completedItems / $totalItems) * 100, 2) : 0;

        // Determine status
        $status = 'not_started';
        if ($progressPercentage == 100) {
            $status = 'completed';
        } elseif ($progressPercentage > 0) {
            $status = 'in_progress';
        }

        // Calculate chapter-level progress
        $chaptersData = $chapters->map(static function ($chapter) use ($curriculumTrackings) {
            // Count total items in this chapter
            $chapterTotalItems =
                $chapter->lectures->count()
                + $chapter->quizzes->count()
                + $chapter->assignments->count()
                + $chapter->resources->count();

            // Get completed items for this chapter
            $chapterCompletedItems = $curriculumTrackings
                ->where('course_chapter_id', $chapter->id)
                ->where('status', 'completed')
                ->count();

            // Calculate chapter progress
            $chapterProgress = $chapterTotalItems > 0
                ? round(($chapterCompletedItems / $chapterTotalItems) * 100, 2)
                : 0;

            // Determine chapter status
            $chapterStatus = 'not_started';
            if ($chapterProgress == 100) {
                $chapterStatus = 'completed';
            } elseif ($chapterProgress > 0) {
                $chapterStatus = 'in_progress';
            }

            // Get last completed date for this chapter
            $chapterLastCompleted = $curriculumTrackings
                ->where('course_chapter_id', $chapter->id)
                ->where('status', 'completed')
                ->sortByDesc('completed_at')
                ->first();

            return [
                'id' => $chapter->id,
                'title' => $chapter->title,
                'lectures_count' => $chapter->lectures->count(),
                'quizzes_count' => $chapter->quizzes->count(),
                'assignments_count' => $chapter->assignments->count(),
                'resources_count' => $chapter->resources->count(),
                'total_items' => $chapterTotalItems,
                'completed_items' => $chapterCompletedItems,
                'progress_percentage' => $chapterProgress,
                'status' => $chapterStatus,
                'completed_at' => $chapterLastCompleted ? $chapterLastCompleted->completed_at : null,
            ];
        });

        // Count completed chapters (chapters with 100% progress)
        $completedChapters = $chaptersData->where('status', 'completed')->count();

        return [
            'total_chapters' => $totalChapters,
            'completed_chapters' => $completedChapters,
            'total_lectures' => $totalLectures,
            'total_items' => $totalItems,
            'completed_items' => $completedItems,
            'progress_percentage' => $progressPercentage,
            'status' => $status,
            'first_tracking_date' => $firstTracking ? $firstTracking->created_at : now(),
            'completed_at' => $lastCompleted ? $lastCompleted->completed_at : null,
            'chapters' => $chaptersData,
        ];
    }

    /**
     * Legacy method for backward compatibility
     */
    private function getDetailedProgress($tracking)
    {
        // If tracking has user_id and course_id properties, use new method
        if (isset($tracking->user_id, $tracking->course_id)) {
            return $this->getDetailedProgressFromCurriculum($tracking->user_id, $tracking->course_id);
        }

        // Fallback to old method if needed
        $course = $tracking->course ?? null;
        $user = $tracking->user ?? null;

        if (!$course || !$user) {
            return [
                'total_chapters' => 0,
                'completed_chapters' => 0,
                'total_lectures' => 0,
                'total_items' => 0,
                'completed_items' => 0,
                'progress_percentage' => 0,
                'status' => 'not_started',
                'chapters' => [],
            ];
        }

        return $this->getDetailedProgressFromCurriculum($user->id, $course->id);
    }

    /**
     * Start tracking a course for a user
     */
    public function startCourseTracking($userId, $courseId)
    {
        $tracking = UserCourseTrack::firstOrCreate([
            'user_id' => $userId,
            'course_id' => $courseId,
        ], [
            'status' => 'started',
        ]);

        return $tracking;
    }

    /**
     * Update chapter progress
     */
    public function updateChapterProgress($userId, $chapterId, $status)
    {
        $tracking = UserCourseChapterTrack::firstOrCreate([
            'user_id' => $userId,
            'course_chapter_id' => $chapterId,
        ], [
            'status' => $status,
        ]);

        $updateData = ['status' => $status];

        if ($status === 'completed') {
            $updateData['completed_at'] = now();
        }

        $tracking->update($updateData);

        // Update course progress
        $this->updateCourseProgress($userId, $chapterId);

        return $tracking;
    }

    /**
     * Update course progress based on chapter completion
     */
    private function updateCourseProgress($userId, $chapterId)
    {
        $chapter = \App\Models\Course\CourseChapter\CourseChapter::find($chapterId);
        if (!$chapter) {
            return;
        }

        $courseId = $chapter->course_id;

        // Get all chapters for this course
        $totalChapters = \App\Models\Course\CourseChapter\CourseChapter::where('course_id', $courseId)->count();

        // Get completed chapters for this user
        $completedChapters = UserCourseChapterTrack::where('user_id', $userId)
            ->whereHas('chapter', static function ($q) use ($courseId): void {
                $q->where('course_id', $courseId);
            })
            ->where('status', 'completed')
            ->count();

        // Determine course status
        $courseStatus = 'started';
        if ($completedChapters == $totalChapters) {
            $courseStatus = 'completed';
        } elseif ($completedChapters > 0) {
            $courseStatus = 'in_progress';
        }

        // Update course tracking
        $courseTracking = UserCourseTrack::where('user_id', $userId)->where('course_id', $courseId)->first();

        if ($courseTracking) {
            $updateData = ['status' => $courseStatus];
            if ($courseStatus === 'completed') {
                $updateData['completed_at'] = now();
            }
            $courseTracking->update($updateData);
        }
    }

    /**
     * API endpoint to update chapter progress
     */
    public function updateChapterProgressApi(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'chapter_id' => 'required|exists:course_chapters,id',
            'status' => 'required|in:started,in_progress,completed',
        ]);

        try {
            $tracking = $this->updateChapterProgress($request->user_id, $request->chapter_id, $request->status);

            return response()->json([
                'success' => true,
                'message' => 'Chapter progress updated successfully',
                'data' => $tracking,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update chapter progress: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API endpoint to get user's course progress
     */
    public function getUserCourseProgress(Request $request, $userId, $courseId)
    {
        try {
            $tracking = UserCourseTrack::with(['user', 'course.user'])
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->first();

            if (!$tracking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Course tracking not found',
                ], 404);
            }

            $progressData = $this->getDetailedProgress($tracking);

            return response()->json([
                'success' => true,
                'data' => [
                    'tracking' => $tracking,
                    'progress' => $progressData,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get course progress: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate progress from curriculum trackings for a user-course combination
     */
    private function calculateProgressFromCurriculum($userId, $courseId)
    {
        // Get all chapters for the course
        $chapters = CourseChapter::where('course_id', $courseId)->with([
            'lectures',
            'quizzes',
            'assignments',
            'resources',
        ])->get();

        // Count total curriculum items (lectures, quizzes, assignments, resources)
        $totalItems = 0;
        foreach ($chapters as $chapter) {
            $totalItems += $chapter->lectures->count();
            $totalItems += $chapter->quizzes->count();
            $totalItems += $chapter->assignments->count();
            $totalItems += $chapter->resources->count();
        }

        if ($totalItems == 0) {
            return [
                'progress_percentage' => 0,
                'status' => 'not_started',
                'first_tracking_date' => now(),
            ];
        }

        // Get completed curriculum items for this user and course
        $completedItems = UserCurriculumTracking::where('user_id', $userId)
            ->whereIn('course_chapter_id', $chapters->pluck('id'))
            ->where('status', 'completed')
            ->count();

        // Get first tracking date
        $firstTracking = UserCurriculumTracking::where('user_id', $userId)
            ->whereIn('course_chapter_id', $chapters->pluck('id'))
            ->orderBy('created_at', 'asc')
            ->first();

        $progressPercentage = round(($completedItems / $totalItems) * 100, 2);

        // Determine status
        $status = 'not_started';
        if ($progressPercentage == 100) {
            $status = 'completed';
        } elseif ($progressPercentage > 0) {
            $status = 'in_progress';
        }

        return [
            'progress_percentage' => $progressPercentage,
            'status' => $status,
            'first_tracking_date' => $firstTracking ? $firstTracking->created_at : now(),
        ];
    }

    /**
     * Get statistics from curriculum tracking
     */
    private function getStatisticsFromCurriculum()
    {
        // Get unique user-course pairs
        $userCoursePairs = UserCurriculumTracking::select(
            'user_curriculum_trackings.user_id',
            'course_chapters.course_id',
        )
            ->join('course_chapters', 'user_curriculum_trackings.course_chapter_id', '=', 'course_chapters.id')
            ->join('users', 'user_curriculum_trackings.user_id', '=', 'users.id')
            ->whereHas('user.orders', static function ($q): void {
                $q->where('status', 'completed');
            })
            ->groupBy('user_curriculum_trackings.user_id', 'course_chapters.course_id')
            ->get();

        $totalEnrollments = $userCoursePairs->count();
        $notStarted = 0;
        $inProgress = 0;
        $completed = 0;
        $totalProgress = 0;

        foreach ($userCoursePairs as $pair) {
            $progressData = $this->calculateProgressFromCurriculum($pair->user_id, $pair->course_id);
            $totalProgress += $progressData['progress_percentage'];

            switch ($progressData['status']) {
                case 'not_started':
                    $notStarted++;
                    break;
                case 'in_progress':
                    $inProgress++;
                    break;
                case 'completed':
                    $completed++;
                    break;
            }
        }

        $averageProgress = $totalEnrollments > 0 ? round($totalProgress / $totalEnrollments, 1) : 0;

        return [
            'total_enrollments' => $totalEnrollments,
            'not_started' => $notStarted,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'average_progress' => $averageProgress,
        ];
    }

    /**
     * Calculate average progress across all enrolled courses (legacy method - kept for compatibility)
     */
    private function calculateAverageProgress()
    {
        return $this->getStatisticsFromCurriculum()['average_progress'];
    }
}
