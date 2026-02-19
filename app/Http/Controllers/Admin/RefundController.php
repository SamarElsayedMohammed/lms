<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course\UserCourseTrack;
use App\Models\RefundRequest;
use App\Services\FileService;
use App\Services\ResponseService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RefundController extends Controller
{
    /**
     * Display refund requests listing
     */
    public function index(Request $request)
    {
        ResponseService::noPermissionThenRedirect('refunds-list');
        $query = RefundRequest::with(['user', 'course', 'transaction', 'processedByUser']);

        // Filter by status
        if ($request->has('status') && $request->status != '') {
            $query->where('status', $request->status);
        }

        // Search functionality
        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(static function ($q) use ($search): void {
                $q->whereHas('user', static function ($userQuery) use ($search): void {
                    $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('course', static function ($courseQuery) use ($search): void {
                    $courseQuery->where('title', 'like', "%{$search}%");
                });
            });
        }

        $refunds = $query->orderBy('created_at', 'desc')->paginate(15);

        return view('refunds.index', [
            'type_menu' => 'refunds',
            'refunds' => $refunds,
            'search' => $request->search,
            'status_filter' => $request->status,
        ]);
    }

    /**
     * Show refund request details
     */
    public function show($id)
    {
        ResponseService::noPermissionThenRedirect('refunds-list');
        $refund = RefundRequest::with([
            'user',
            'course',
            'transaction',
            'transaction.order',
            'processedByUser',
        ])->findOrFail($id);

        // Calculate course progress
        $courseProgress = $this->calculateCourseProgress($refund->user_id, $refund->course_id);

        return view('refunds.show', [
            'type_menu' => 'refunds',
            'refund' => $refund,
            'courseProgress' => $courseProgress,
        ]);
    }

    /**
     * Calculate course progress from user_curriculum_trackings
     */
    private function calculateCourseProgress($userId, $courseId)
    {
        try {
            $course = \App\Models\Course\Course::with([
                'chapters' => static function ($q): void {
                    $q->with([
                        'lectures',
                        'quizzes',
                        'assignments',
                        'resources',
                    ]);
                },
            ])->find($courseId);

            if (!$course) {
                return null;
            }

            $chapters = $course->chapters;
            $totalChapters = $chapters->count();

            // Calculate total curriculum items
            $totalCurriculumItems = $chapters->sum(
                static fn($chapter) => (
                    $chapter->lectures->count()
                    + $chapter->quizzes->count()
                    + $chapter->assignments->count()
                    + $chapter->resources->count()
                ),
            );

            // Get chapter IDs
            $chapterIds = $chapters->pluck('id')->toArray();

            // Get completed items count
            $completedCurriculumItems = 0;
            $completedChapters = 0;
            $progressPercentage = 0;

            if (!empty($chapterIds)) {
                $completedCurriculumItems = \App\Models\UserCurriculumTracking::where('user_id', $userId)
                    ->whereIn('course_chapter_id', $chapterIds)
                    ->where('status', 'completed')
                    ->count();

                // Calculate completed chapters
                foreach ($chapters as $chapter) {
                    $chapterTotalItems =
                        $chapter->lectures->count()
                        + $chapter->quizzes->count()
                        + $chapter->assignments->count()
                        + $chapter->resources->count();

                    if ($chapterTotalItems > 0) {
                        $chapterCompletedItems = \App\Models\UserCurriculumTracking::where('user_id', $userId)
                            ->where('course_chapter_id', $chapter->id)
                            ->where('status', 'completed')
                            ->count();

                        if ($chapterCompletedItems >= $chapterTotalItems) {
                            $completedChapters++;
                        }
                    }
                }

                // Calculate progress percentage
                if ($totalCurriculumItems > 0) {
                    $progressPercentage = round(min(100, ($completedCurriculumItems / $totalCurriculumItems) * 100), 2);
                }
            }

            // Get first tracking date
            $firstTracking = \App\Models\UserCurriculumTracking::where('user_id', $userId)
                ->whereIn('course_chapter_id', $chapterIds)
                ->orderBy('created_at', 'asc')
                ->first();

            // Get last completed date
            $lastCompleted = \App\Models\UserCurriculumTracking::where('user_id', $userId)
                ->whereIn('course_chapter_id', $chapterIds)
                ->where('status', 'completed')
                ->orderBy('completed_at', 'desc')
                ->first();

            return [
                'total_chapters' => $totalChapters,
                'completed_chapters' => $completedChapters,
                'total_curriculum_items' => $totalCurriculumItems,
                'completed_curriculum_items' => $completedCurriculumItems,
                'progress_percentage' => $progressPercentage,
                'first_tracking_date' => $firstTracking ? $firstTracking->created_at : null,
                'last_completed_date' => $lastCompleted ? $lastCompleted->completed_at : null,
            ];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Process refund request (approve/reject)
     */
    public function process(Request $request, $id)
    {
        ResponseService::noPermissionThenRedirect('refunds-process');
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect()->route('admin.refunds.show', $id)->withErrors($validator)->withInput();
        }

        try {
            DB::beginTransaction();

            $refundRequest = RefundRequest::with(['user', 'course', 'transaction'])->findOrFail($id);

            if ($refundRequest->status !== 'pending') {
                return redirect()
                    ->route('admin.refunds.show', $id)
                    ->with('error', 'This refund request has already been processed');
            }

            if ($request->action === 'approve') {
                // Delete existing receipt if any
                if ($refundRequest->admin_receipt) {
                    FileService::delete($refundRequest->admin_receipt);
                }

                // Credit amount to user's wallet using WalletService
                WalletService::creditWallet(
                    $refundRequest->user_id,
                    $refundRequest->refund_amount,
                    'refund',
                    "Refund for course: {$refundRequest->course->title}",
                    $refundRequest->id,
                    \App\Models\RefundRequest::class,
                    'user', // User-side entry
                );

                // Remove course access
                UserCourseTrack::where([
                    'user_id' => $refundRequest->user_id,
                    'course_id' => $refundRequest->course_id,
                ])->delete();

                $refundRequest->update([
                    'status' => 'approved',
                    'admin_notes' => $request->admin_notes,
                    'admin_receipt' => null, // Remove receipt from database
                    'processed_at' => Carbon::now(),
                    'processed_by' => auth()->id(),
                ]);

                DB::commit();

                return redirect()
                    ->route('admin.refunds.show', $id)
                    ->with(
                        'success',
                        "Refund approved and processed successfully for {$refundRequest->user->name}. Amount credited to user's wallet.",
                    );
            } else {
                // Reject refund
                $refundRequest->update([
                    'status' => 'rejected',
                    'admin_notes' => $request->admin_notes,
                    'processed_at' => Carbon::now(),
                    'processed_by' => auth()->id(),
                ]);

                DB::commit();

                return redirect()
                    ->route('admin.refunds.show', $id)
                    ->with('success', 'Refund request rejected successfully.');
            }
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()
                ->route('admin.refunds.show', $id)
                ->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Get refund statistics for dashboard
     */
    public function getStats()
    {
        $stats = [
            'total_requests' => RefundRequest::count(),
            'pending_requests' => RefundRequest::where('status', 'pending')->count(),
            'approved_requests' => RefundRequest::where('status', 'approved')->count(),
            'rejected_requests' => RefundRequest::where('status', 'rejected')->count(),
            'total_refunded_amount' => RefundRequest::where('status', 'approved')->sum('refund_amount'),
        ];

        return $stats;
    }
}
