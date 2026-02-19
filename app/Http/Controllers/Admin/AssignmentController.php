<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Models\User;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AssignmentController extends Controller
{
    /**
     * Display all assignment submissions
     */
    public function index(Request $request)
    {
        ResponseService::noPermissionThenRedirect('assignments-list');
        try {
            // Get assignment submissions

            $query = UserAssignmentSubmission::with([
                'user:id,name,email,profile',
                'assignment:id,title,points,course_chapter_id',
                'assignment.chapter:id,title,course_id',
                'assignment.chapter.course:id,title,user_id',
                'assignment.chapter.course.user:id,name,email',
                'files',
            ]);

            // Apply filters
            if ($request->filled('course_id')) {
                $query->whereHas('assignment.chapter', static function ($chapterQuery) use ($request): void {
                    $chapterQuery->where('course_id', $request->course_id);
                });
            }

            if ($request->filled('instructor_id')) {
                $query->whereHas('assignment.chapter.course', static function ($courseQuery) use ($request): void {
                    $courseQuery->where('user_id', $request->instructor_id);
                });
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->whereHas('user', static function ($userQuery) use ($search): void {
                            $userQuery->where('name', 'LIKE', "%{$search}%")->orWhere('email', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('assignment', static function ($assignmentQuery) use ($search): void {
                            $assignmentQuery->where('title', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('assignment.chapter.course', static function ($courseQuery) use ($search): void {
                            $courseQuery->where('title', 'LIKE', "%{$search}%");
                        });
                });
            }

            $submissions = $query->orderBy('created_at', 'desc')->paginate(15);

            // Get filter data
            $courses = Course::select('id', 'title')->get();
            $instructors = User::whereHas('roles', static function ($query): void {
                $query->where('name', 'Instructor');
            })->select('id', 'name', 'email')->get();

            return view('admin.assignments.index', [
                'type_menu' => 'assignments',
                'submissions' => $submissions,
                'courses' => $courses,
                'instructors' => $instructors,
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to load assignment submissions: ' . $e->getMessage());
        }
    }

    /**
     * Display pending assignment submissions
     */
    public function pending(Request $request)
    {
        $request->merge(['status' => 'submitted']);

        return $this->index($request);
    }

    /**
     * Display accepted assignment submissions
     */
    public function accepted(Request $request)
    {
        $request->merge(['status' => 'accepted']);

        return $this->index($request);
    }

    /**
     * Display rejected assignment submissions
     */
    public function rejected(Request $request)
    {
        $request->merge(['status' => 'rejected']);

        return $this->index($request);
    }

    /**
     * Display assignment statistics
     */
    public function statistics(Request $request)
    {
        try {
            $query = UserAssignmentSubmission::query();

            // Apply date filters
            if ($request->filled('date_from')) {
                $query->whereRaw('DATE(user_assignment_submissions.created_at) >= ?', [$request->date_from]);
            }
            if ($request->filled('date_to')) {
                $query->whereRaw('DATE(user_assignment_submissions.created_at) <= ?', [$request->date_to]);
            }

            // Apply course filter
            if ($request->filled('course_id')) {
                $query->whereHas('assignment.chapter', static function ($chapterQuery) use ($request): void {
                    $chapterQuery->where('course_id', $request->course_id);
                });
            }

            // Get statistics
            $totalSubmissions = $query->count();
            $pendingSubmissions = (clone $query)->where('status', 'submitted')->count();
            $acceptedSubmissions = (clone $query)->where('status', 'accepted')->count();
            $rejectedSubmissions = (clone $query)->where('status', 'rejected')->count();

            // Get submissions by status
            $statusBreakdown = (clone $query)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Get submissions by course
            $courseBreakdown = (clone $query)
                ->join(
                    'course_chapter_assignments',
                    'user_assignment_submissions.course_chapter_assignment_id',
                    '=',
                    'course_chapter_assignments.id',
                )
                ->join('course_chapters', 'course_chapter_assignments.course_chapter_id', '=', 'course_chapters.id')
                ->join('courses', 'course_chapters.course_id', '=', 'courses.id')
                ->selectRaw('courses.title as course_name, COUNT(*) as count')
                ->groupBy('courses.id', 'courses.title')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get();

            // Get recent submissions
            $recentSubmissions = (clone $query)
                ->with([
                    'user:id,name,email',
                    'assignment:id,title,course_chapter_id',
                    'assignment.chapter:id,title,course_id',
                    'assignment.chapter.course:id,title',
                ])
                ->orderBy('user_assignment_submissions.created_at', 'desc')
                ->limit(10)
                ->get();

            // Get filter data
            $courses = Course::select('id', 'title')->get();

            $statistics = [
                'overview' => [
                    'total_submissions' => $totalSubmissions,
                    'pending_submissions' => $pendingSubmissions,
                    'accepted_submissions' => $acceptedSubmissions,
                    'rejected_submissions' => $rejectedSubmissions,
                    'acceptance_rate' => $totalSubmissions > 0
                        ? round(($acceptedSubmissions / $totalSubmissions) * 100, 2)
                        : 0,
                ],
                'status_breakdown' => $statusBreakdown,
                'course_breakdown' => $courseBreakdown,
                'recent_submissions' => $recentSubmissions,
            ];

            return view('admin.assignments.statistics', [
                'type_menu' => 'assignments',
                'statistics' => $statistics,
                'courses' => $courses,
            ]);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to load statistics: ' . $e->getMessage());
        }
    }

    /**
     * Display specific assignment submission
     */
    public function show($id)
    {
        ResponseService::noPermissionThenRedirect('assignments-list');
        try {
            $submission = UserAssignmentSubmission::with([
                'user:id,name,email,profile',
                'assignment:id,title,points,course_chapter_id',
                'assignment.chapter:id,title,course_id',
                'assignment.chapter.course:id,title,slug,user_id',
                'assignment.chapter.course.user:id,name,email',
                'files',
            ])->findOrFail($id);

            // Check if relationships exist
            if (!$submission->user) {
                return redirect()
                    ->route('admin.assignments.index')
                    ->with('error', 'User not found for this submission.');
            }

            if (!$submission->assignment) {
                return redirect()
                    ->route('admin.assignments.index')
                    ->with('error', 'Assignment not found for this submission.');
            }

            if (!$submission->assignment->chapter) {
                return redirect()
                    ->route('admin.assignments.index')
                    ->with('error', 'Chapter not found for this assignment.');
            }

            if (!$submission->assignment->chapter->course) {
                return redirect()
                    ->route('admin.assignments.index')
                    ->with('error', 'Course not found for this chapter.');
            }

            return view('admin.assignments.show', [
                'type_menu' => 'assignments',
                'submission' => $submission,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return redirect()->route('admin.assignments.index')->with('error', 'Assignment submission not found.');
        } catch (\Exception $e) {
            Log::error('Error loading assignment submission: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->route('admin.assignments.index')
                ->with('error', 'Failed to load assignment submission: ' . $e->getMessage());
        }
    }

    /**
     * Update assignment submission status
     */
    public function updateStatus(Request $request, $id)
    {
        ResponseService::noPermissionThenRedirect('assignments-review');
        try {
            // Make feedback required when status is rejected or suspended
            $feedbackRule = in_array($request->status, ['rejected', 'suspended'])
                ? 'required|string|max:1000'
                : 'nullable|string|max:1000';

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:accepted,rejected,suspended',
                'points' => 'nullable|numeric|min:0',
                'feedback' => $feedbackRule,
            ]);

            if ($validator->fails()) {
                return redirect()->back()->withErrors($validator)->withInput();
            }

            $submission = UserAssignmentSubmission::findOrFail($id);

            $updateData = [
                'status' => $request->status,
            ];

            if ($request->status === 'accepted' && $request->has('points')) {
                $updateData['points'] = $request->points;
            }

            // Update feedback for rejected/suspended, or allow it for any status
            if ($request->has('feedback')) {
                $updateData['feedback'] = $request->feedback;
            }

            $submission->update($updateData);

            return redirect()->back()->with('success', 'Assignment submission updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to update assignment submission: ' . $e->getMessage());
        }
    }

    /**
     * Bulk update assignment submissions
     */
    public function bulkUpdate(Request $request)
    {
        try {
            // Handle submission_ids - can be array or comma-separated string
            $submissionIdsInput = $request->input('submission_ids');
            if (is_string($submissionIdsInput)) {
                $submissionIds = array_filter(array_map('trim', explode(',', $submissionIdsInput)));
            } else {
                $submissionIds = is_array($submissionIdsInput) ? array_filter($submissionIdsInput) : [];
            }

            if (empty($submissionIds)) {
                return redirect()->back()->with('error', 'Please select at least one submission.');
            }

            // Make feedback required when status is rejected or suspended
            $feedbackRule = in_array($request->status, ['rejected', 'suspended'])
                ? 'required|string|max:1000'
                : 'nullable|string|max:1000';

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:accepted,rejected,suspended',
                'points' => 'nullable|numeric|min:0',
                'feedback' => $feedbackRule,
                'admin_comment' => 'nullable|string|max:1000',
            ]);

            // Validate submission IDs exist
            $validIds = UserAssignmentSubmission::whereIn('id', $submissionIds)->pluck('id')->toArray();
            $invalidIds = array_diff($submissionIds, $validIds);

            if (!empty($invalidIds)) {
                return redirect()->back()->with('error', 'Some selected submissions are invalid.');
            }

            if ($validator->fails()) {
                return redirect()->back()->withErrors($validator)->withInput();
            }

            // Use only valid IDs
            $submissionIds = $validIds;
            $status = $request->status;

            $updateData = [
                'status' => $status,
            ];

            if ($status === 'accepted' && $request->has('points')) {
                $updateData['points'] = $request->points;
            }

            if (in_array($status, ['rejected', 'suspended']) && $request->has('feedback')) {
                $updateData['feedback'] = $request->feedback;
            }

            if ($request->has('admin_comment') && !empty($request->admin_comment)) {
                $updateData['comment'] = $request->admin_comment;
            }

            $updatedCount = UserAssignmentSubmission::whereIn('id', $submissionIds)->update($updateData);

            return redirect()->back()->with('success', "Successfully updated {$updatedCount} assignment submissions");
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Failed to bulk update assignment submissions: ' . $e->getMessage());
        }
    }

    /**
     * Get dashboard data for AJAX requests
     */
    public function getDashboardData(Request $request)
    {
        try {
            $query = UserAssignmentSubmission::query();

            // Apply filters
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $totalSubmissions = $query->count();
            $pendingSubmissions = (clone $query)->where('status', 'submitted')->count();
            $acceptedSubmissions = (clone $query)->where('status', 'accepted')->count();
            $rejectedSubmissions = (clone $query)->where('status', 'rejected')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_submissions' => $totalSubmissions,
                    'pending_submissions' => $pendingSubmissions,
                    'accepted_submissions' => $acceptedSubmissions,
                    'rejected_submissions' => $rejectedSubmissions,
                    'acceptance_rate' => $totalSubmissions > 0
                        ? round(($acceptedSubmissions / $totalSubmissions) * 100, 2)
                        : 0,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
