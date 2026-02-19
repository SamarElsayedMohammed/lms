<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\UserCourseTrack;
use App\Models\OrderCourse;
use App\Models\RefundRequest;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\ApiResponseService;
use App\Services\FileService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RefundApiController extends Controller
{
    /**
     * Request a refund for a course
     */
    public function requestRefund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'reason' => 'nullable|string|max:1000',
            'user_media' => 'nullable|file|mimes:jpg,jpeg,png,gif,mp4,avi,mov,pdf,doc,docx|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $courseId = $request->course_id;

            // Check if refunds are enabled
            $refundEnabled = Setting::where('name', 'refund_enabled')->first();
            if (!$refundEnabled || !$refundEnabled->value) {
                return ApiResponseService::validationError('Refunds are currently disabled');
            }

            // Get refund period from settings
            $refundPeriodDays = Setting::where('name', 'refund_period_days')->first();
            $refundPeriod = $refundPeriodDays ? (int) $refundPeriodDays->value : 7;

            // Check if user has purchased this course through transactions->orders->order_courses
            $transaction = Transaction::whereHas('order', static function ($query) use ($user, $courseId): void {
                $query
                    ->where('user_id', $user?->id)
                    ->where('status', 'completed')
                    ->whereHas('orderCourses', static function ($subQuery) use ($courseId): void {
                        $subQuery->where('course_id', $courseId);
                    });
            })
                ->where('status', 'completed')
                ->first();

            if (!$transaction) {
                return ApiResponseService::validationError("You haven't purchased this course");
            }

            // Get the order course details for refund amount
            $orderCourse = OrderCourse::whereHas('order', static function ($query) use ($user): void {
                $query->where('user_id', $user?->id)->where('status', 'completed');
            })
                ->where('course_id', $courseId)
                ->first();

            if (!$orderCourse) {
                return ApiResponseService::validationError('Course purchase details not found');
            }

            // Check if refund period is still valid
            $purchaseDate = $transaction->created_at;
            $refundDeadline = Carbon::parse($purchaseDate)->addDays($refundPeriod);

            if (Carbon::now()->greaterThan($refundDeadline)) {
                return ApiResponseService::validationError(
                    "Refund period has expired. You can only request refunds within {$refundPeriod} days of purchase",
                );
            }

            // Check if refund already requested
            $existingRefund = RefundRequest::where([
                'user_id' => $user?->id,
                'course_id' => $courseId,
                'transaction_id' => $transaction->id,
            ])->whereIn('status', ['pending', 'approved'])->first();

            if ($existingRefund) {
                return ApiResponseService::validationError(
                    'A refund request for this course is already pending or approved',
                );
            }

            // Handle user media upload
            $userMediaPath = null;
            if ($request->hasFile('user_media')) {
                $userMediaPath = FileService::upload($request->file('user_media'), 'refunds/user-media');
            }

            // Create refund request
            // Calculate refund amount: price (which is discounted price) + tax
            $refundAmount = $orderCourse->price + $orderCourse->tax_price;
            $refundRequest = RefundRequest::create([
                'user_id' => $user->id,
                'course_id' => $courseId,
                'transaction_id' => $transaction->id,
                'refund_amount' => $refundAmount,
                'status' => 'pending',
                'reason' => $request->reason,
                'user_media' => $userMediaPath,
                'purchase_date' => $purchaseDate,
                'request_date' => Carbon::now(),
            ]);

            return ApiResponseService::successResponse(
                'Refund request submitted successfully. It will be reviewed by our team',
                $refundRequest,
            );
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Get user's refund requests with pagination
     */
    public function getUserRefunds(Request $request)
    {
        try {
            $user = Auth::user();

            // Get per_page parameter with default of 10 records per page
            $perPage = $request->get('per_page', 10);

            // Validate per_page parameter (max 50 records per page)
            if ($perPage > 50) {
                $perPage = 50;
            }

            $refunds = RefundRequest::with([
                'course' => static function ($query): void {
                    $query
                        ->select('id', 'title', 'thumbnail', 'user_id')
                        ->with(['user' => static function ($userQuery): void {
                            $userQuery->select('id', 'name');
                        }]);
                },
                'transaction',
                'transaction.order',
            ])
                ->where('user_id', $user?->id)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Transform the data to include creator name and media URLs
            $refunds
                ->getCollection()
                ->transform(static function ($refund) {
                    if ($refund->course) {
                        if ($refund->course->relationLoaded('user') && $refund->course->user) {
                            $refund->course->creator_name = $refund->course->user->name;
                            // Remove user object from course
                            unset($refund->course->user);
                        } else {
                            $refund->course->creator_name = null;
                        }
                    }
                    // Add media URLs
                    $refund->user_media_url = $refund->user_media ? FileService::getFileUrl($refund->user_media) : null;
                    $refund->admin_receipt_url = $refund->admin_receipt
                        ? FileService::getFileUrl($refund->admin_receipt)
                        : null;
                    return $refund;
                });

            return ApiResponseService::successResponse('Refund requests retrieved successfully', $refunds);
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Check refund eligibility for a course
     */
    public function checkRefundEligibility(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();
            $courseId = $request->course_id;

            // Check if refunds are enabled
            $refundEnabled = Setting::where('name', 'refund_enabled')->first();
            if (!$refundEnabled || !$refundEnabled->value) {
                return ApiResponseService::successResponse('Refund eligibility checked', [
                    'eligible' => false,
                    'reason' => 'Refunds are currently disabled',
                ]);
            }

            // Get refund period from settings
            $refundPeriodDays = Setting::where('name', 'refund_period_days')->first();
            $refundPeriod = $refundPeriodDays ? (int) $refundPeriodDays->value : 7;

            // Check if user has purchased this course through transactions->orders->order_courses
            $transaction = Transaction::whereHas('order', static function ($query) use ($user, $courseId): void {
                $query
                    ->where('user_id', $user?->id)
                    ->where('status', 'completed')
                    ->whereHas('orderCourses', static function ($subQuery) use ($courseId): void {
                        $subQuery->where('course_id', $courseId);
                    });
            })
                ->where('status', 'completed')
                ->first();

            if (!$transaction) {
                return ApiResponseService::successResponse('Refund eligibility checked', [
                    'eligible' => false,
                    'reason' => 'Course not purchased',
                ]);
            }

            // Get the order course details for refund amount
            $orderCourse = OrderCourse::whereHas('order', static function ($query) use ($user): void {
                $query->where('user_id', $user?->id)->where('status', 'completed');
            })
                ->where('course_id', $courseId)
                ->first();

            if (!$orderCourse) {
                return ApiResponseService::successResponse('Refund eligibility checked', [
                    'eligible' => false,
                    'reason' => 'Course purchase details not found',
                ]);
            }

            // Check if refund already requested
            $existingRefund = RefundRequest::where([
                'user_id' => $user?->id,
                'course_id' => $courseId,
                'transaction_id' => $transaction->id,
            ])->whereIn('status', ['pending', 'approved'])->first();

            if ($existingRefund) {
                return ApiResponseService::successResponse('Refund eligibility checked', [
                    'eligible' => false,
                    'reason' => 'Refund already requested',
                    'existing_status' => $existingRefund->status,
                ]);
            }

            // Check if refund period is still valid
            $purchaseDate = $transaction->created_at;
            $refundDeadline = Carbon::parse($purchaseDate)->addDays($refundPeriod);
            $daysLeft = Carbon::now()->diffInDays($refundDeadline, false);

            if ($daysLeft < 0) {
                return ApiResponseService::successResponse('Refund eligibility checked', [
                    'eligible' => false,
                    'reason' => 'Refund period expired',
                    'refund_period_days' => $refundPeriod,
                ]);
            }

            $refundAmount = $orderCourse->price + $orderCourse->tax_price;
            return ApiResponseService::successResponse('Refund eligibility checked', [
                'eligible' => true,
                'refund_amount' => $refundAmount,
                'days_left' => $daysLeft,
                'refund_deadline' => $refundDeadline->format('Y-m-d H:i:s'),
                'purchase_date' => $purchaseDate->format('Y-m-d H:i:s'),
                'refund_period_days' => $refundPeriod,
            ]);
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Process refund (for admin)
     */
    public function processRefund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refund_request_id' => 'required|exists:refund_requests,id',
            'action' => 'required|in:approve,reject',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $refundRequest = RefundRequest::with([
                'user',
                'course',
                'transaction',
            ])->findOrFail($request->refund_request_id);

            if ($refundRequest->status !== 'pending') {
                DB::rollBack();
                return ApiResponseService::validationError('This refund request has already been processed');
            }

            $admin = Auth::user();

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
                    'processed_by' => $admin?->id,
                ]);

                // Add media URLs to response
                $refundRequest->user_media_url = $refundRequest->user_media
                    ? FileService::getFileUrl($refundRequest->user_media)
                    : null;
                $refundRequest->admin_receipt_url = null; // No receipt stored

                DB::commit();
                return ApiResponseService::successResponse(
                    'Refund approved and processed successfully',
                    $refundRequest,
                );
            } else {
                $refundRequest->update([
                    'status' => 'rejected',
                    'admin_notes' => $request->admin_notes,
                    'processed_at' => Carbon::now(),
                    'processed_by' => $admin?->id,
                ]);

                DB::commit();
                return ApiResponseService::successResponse('Refund request rejected');
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Get refund settings (for admin)
     */
    public function getRefundSettings()
    {
        try {
            $settings = Setting::whereIn('name', ['refund_enabled', 'refund_period_days'])->get();

            $formattedSettings = [];
            foreach ($settings as $setting) {
                $formattedSettings[$setting->name] = $setting->value;
            }

            return ApiResponseService::successResponse('Refund settings retrieved successfully', $formattedSettings);
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Update refund settings (for admin)
     */
    public function updateRefundSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refund_enabled' => 'required|boolean',
            'refund_period_days' => 'required|integer|min:1|max:90',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $settings = [
                'refund_enabled' => [
                    'value' => $request->refund_enabled ? '1' : '0',
                    'type' => 'boolean',
                ],
                'refund_period_days' => [
                    'value' => (string) $request->refund_period_days,
                    'type' => 'number',
                ],
            ];

            foreach ($settings as $name => $data) {
                Setting::updateOrCreate(['name' => $name], ['value' => $data['value'], 'type' => $data['type']]);
            }

            return ApiResponseService::successResponse('Refund settings updated successfully');
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Get all refund requests (for admin)
     */
    public function getAllRefunds(Request $request)
    {
        try {
            $query = RefundRequest::with(['user', 'course', 'transaction', 'processedByUser']);

            // Filter by status if provided
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Search by user name or course title
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->whereHas('user', static function ($q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('course', static function ($q) use ($search): void {
                    $q->where('title', 'like', "%{$search}%");
                });
            }

            $refunds = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

            // Add media URLs to response
            $refunds
                ->getCollection()
                ->transform(static function ($refund) {
                    $refund->user_media_url = $refund->user_media ? FileService::getFileUrl($refund->user_media) : null;
                    $refund->admin_receipt_url = $refund->admin_receipt
                        ? FileService::getFileUrl($refund->admin_receipt)
                        : null;
                    return $refund;
                });

            return ApiResponseService::successResponse('Refund requests retrieved successfully', $refunds);
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }
}
