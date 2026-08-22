<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\UserCourseTrack;
use App\Models\OrderCourse;
use App\Models\RefundRequest;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\ApiResponseService;
use App\Services\AuditLogService;
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
     * Enforce admin role and permission for financial refund actions.
     */
    protected function ensureAdminPermission(string $permission = 'finance-list'): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(401, 'Unauthenticated');
        }
        $adminRoles = ['Super Admin', config('constants.SYSTEM_ROLES.SUPER_ADMIN'), config('constants.SYSTEM_ROLES.STAFF'), config('constants.SYSTEM_ROLES.SUPERVISOR')];
        if (!$user->hasAnyRole($adminRoles, 'web') && !$user->can($permission)) {
            abort(403, 'Admin access required for refunds management');
        }
    }

    /**
     * Request a refund for a course
     */
    public function requestRefund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'reason' => 'required|string|max:1000',
            'user_media' => 'nullable|file|mimes:jpg,jpeg,png,gif,mp4,avi,mov,pdf,doc,docx|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $userMediaPath = null;

        try {
            $user = Auth::user();
            $courseId = $request->course_id;

            // Check if refunds are enabled
            $refundEnabled = Setting::where('name', 'refund_enabled')->first();
            if (!$refundEnabled || !filter_var($refundEnabled->value, FILTER_VALIDATE_BOOLEAN)) {
                return ApiResponseService::validationError('Refunds are currently disabled');
            }

            // Get refund period from settings
            $refundPeriodDays = Setting::where('name', 'refund_period_days')->first();
            $refundPeriod = $refundPeriodDays ? max(0, (int) $refundPeriodDays->value) : 7;

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
                ->latest('created_at')
                ->first();

            if (!$transaction) {
                return ApiResponseService::validationError("You haven't purchased this course");
            }

            // Get the order course details for refund amount
            $orderCourse = OrderCourse::where('order_id', $transaction->order_id)
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
            if ($request->hasFile('user_media')) {
                $userMediaPath = FileService::upload($request->file('user_media'), 'refunds/user-media');
            }

            // Create refund request
            // Calculate refund amount: price (which is discounted price) + tax
            $refundAmount = $orderCourse->price + $orderCourse->tax_price;
            // Also calculate EGP refund if amount_egp is available, else fallback to refundAmount assuming it's EGP
            $exchangeRate = (float) ($orderCourse->exchange_rate_snapshot ?: 1);
            $refundAmountEgp = round($refundAmount * $exchangeRate, 2);
            if ($refundAmountEgp <= 0) {
                return ApiResponseService::validationError('Free purchases are not eligible for a monetary refund');
            }

            $refundRequest = DB::transaction(function () use (
                $transaction,
                $user,
                $courseId,
                $refundAmount,
                $refundAmountEgp,
                $orderCourse,
                $exchangeRate,
                $request,
                $userMediaPath,
                $purchaseDate,
            ) {
                // Serialize against the purchase so parallel browser tabs cannot
                // create active refunds for the same transaction and course.
                Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

                $existingRefund = RefundRequest::where([
                    'user_id' => $user->id,
                    'course_id' => $courseId,
                    'transaction_id' => $transaction->id,
                ])->whereIn('status', ['pending', 'approved'])->first();

                if ($existingRefund !== null) {
                    return null;
                }

                return RefundRequest::create([
                    'user_id' => $user->id,
                    'course_id' => $courseId,
                    'transaction_id' => $transaction->id,
                    'refund_amount' => $refundAmount,
                    'amount_egp' => $refundAmountEgp,
                    'currency_code' => strtoupper((string) ($orderCourse->currency_code ?? 'EGP')),
                    'exchange_rate_snapshot' => $exchangeRate,
                    'status' => 'pending',
                    'reason' => trim((string) $request->reason),
                    'user_media' => $userMediaPath,
                    'purchase_date' => $purchaseDate,
                    'request_date' => Carbon::now(),
                ]);
            });

            if ($refundRequest === null) {
                if ($userMediaPath !== null) {
                    FileService::delete($userMediaPath);
                    $userMediaPath = null;
                }

                return ApiResponseService::validationError(
                    'A refund request for this course is already pending or approved',
                );
            }

            return ApiResponseService::successResponse(
                'Refund request submitted successfully. It will be reviewed by our team',
                $refundRequest,
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($userMediaPath !== null) {
                FileService::delete($userMediaPath);
            }
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

            $validated = $request->validate([
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:50',
                'status' => 'nullable|string|in:pending,approved,rejected',
            ]);

            // Get per_page parameter with default of 10 records per page
            $perPage = (int) ($validated['per_page'] ?? 10);

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
                ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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
            if (!$refundEnabled || !filter_var($refundEnabled->value, FILTER_VALIDATE_BOOLEAN)) {
                return ApiResponseService::successResponse('Refund eligibility checked', [
                    'eligible' => false,
                    'reason' => 'Refunds are currently disabled',
                ]);
            }

            // Get refund period from settings
            $refundPeriodDays = Setting::where('name', 'refund_period_days')->first();
            $refundPeriod = $refundPeriodDays ? max(0, (int) $refundPeriodDays->value) : 7;

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
                ->latest('created_at')
                ->first();

            if (!$transaction) {
                return ApiResponseService::successResponse('Refund eligibility checked', [
                    'eligible' => false,
                    'reason' => 'Course not purchased',
                ]);
            }

            // Get the order course details for refund amount
            $orderCourse = OrderCourse::where('order_id', $transaction->order_id)
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
            $exchangeRate = (float) ($orderCourse->exchange_rate_snapshot ?: 1);
            $refundAmountEgp = round($refundAmount * $exchangeRate, 2);
            if ($refundAmountEgp <= 0) {
                return ApiResponseService::successResponse('Refund eligibility checked', [
                    'eligible' => false,
                    'reason' => 'Free purchases are not eligible for a monetary refund',
                ]);
            }
            return ApiResponseService::successResponse('Refund eligibility checked', [
                'eligible' => true,
                'refund_amount' => $refundAmount,
                'refund_amount_egp' => $refundAmountEgp,
                'currency_code' => strtoupper((string) ($orderCourse->currency_code ?? 'EGP')),
                'days_left' => $daysLeft,
                'refund_deadline' => $refundDeadline->toIso8601String(),
                'purchase_date' => $purchaseDate->toIso8601String(),
                'refund_period_days' => $refundPeriod,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Process refund (for admin)
     */
    public function processRefund(Request $request)
    {
        $this->ensureAdminPermission('finance-edit');

        $validator = Validator::make($request->all(), [
            'refund_request_id' => 'required|exists:refund_requests,id',
            'action' => 'required|in:approve,reject',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            return DB::transaction(function () use ($request) {
                $refundRequest = RefundRequest::lockForUpdate()->findOrFail($request->refund_request_id);
                $refundRequest->load(['user', 'course' => fn ($q) => $q->withTrashed(), 'transaction']);

                if ($refundRequest->status !== 'pending') {
                    return ApiResponseService::validationError('This refund request has already been processed');
                }

                $admin = Auth::user();

                if ($request->action === 'approve') {
                    // Lock student and course (including soft-deleted)
                    $student = \App\Models\User::lockForUpdate()->find($refundRequest->user_id);
                    $course = \App\Models\Course\Course::withTrashed()->lockForUpdate()->find($refundRequest->course_id);

                    // Delete existing receipt if any
                    if ($refundRequest->admin_receipt) {
                        FileService::delete($refundRequest->admin_receipt);
                    }

                    $courseTitle = $course?->title ?? 'Course #' . $refundRequest->course_id;

                    // 1. Credit amount to student's wallet using WalletService with EGP amount
                    $refundAmountEgp = (float) ($refundRequest->amount_egp ?? $refundRequest->refund_amount);
                    if ($refundAmountEgp <= 0) {
                        return ApiResponseService::validationError('Refund amount must be greater than zero');
                    }

                    WalletService::creditWallet(
                        $refundRequest->user_id,
                        $refundAmountEgp,
                        'refund',
                        "Refund for course: {$courseTitle}",
                        $refundRequest->id,
                        \App\Models\RefundRequest::class,
                        'user'
                    );

                    // 2. Handle instructor commission clawback (null-safe)
                    $orderId = $refundRequest->transaction?->order_id;
                    $commission = null;
                    if ($orderId) {
                        $commission = \App\Models\Commission::where('order_id', $orderId)
                            ->where('course_id', $refundRequest->course_id)
                            ->where('status', 'paid')
                            ->first();
                    }

                    if ($commission) {
                        $instructorId = $commission->instructor_id ?: ($course?->user_id ?? $course?->instructor_id);
                        $instructor = $instructorId ? \App\Models\User::withTrashed()->find($instructorId) : null;

                        if ($instructor) {
                            try {
                                WalletService::debitWallet(
                                    $instructor->id,
                                    $commission->instructor_commission_amount,
                                    'refund',
                                    "Commission clawback for refunded course: {$courseTitle}",
                                    $refundRequest->id,
                                    \App\Models\RefundRequest::class,
                                    'instructor',
                                    true // allow negative balance for clawback recovery
                                );
                                $commission->update(['status' => 'refunded']);
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('Instructor commission clawback debit failed', [
                                    'instructor_id' => $instructor->id,
                                    'commission_id' => $commission->id,
                                    'error' => $e->getMessage(),
                                ]);
                                $commission->update(['status' => 'pending_recovery']);
                            }
                        } else {
                            \Illuminate\Support\Facades\Log::warning('Instructor record not found for commission clawback', [
                                'instructor_id' => $instructorId,
                                'commission_id' => $commission->id,
                            ]);
                            $commission->update(['status' => 'refunded']);
                        }
                    }

                    // 3. Remove student course access and enrollment tracks
                    UserCourseTrack::where([
                        'user_id' => $refundRequest->user_id,
                        'course_id' => $refundRequest->course_id,
                    ])->delete();

                    if (\Illuminate\Support\Facades\Schema::hasTable('course_user')) {
                        DB::table('course_user')
                            ->where('user_id', $refundRequest->user_id)
                            ->where('course_id', $refundRequest->course_id)
                            ->delete();
                    }

                    // Preserve immutable purchase rows for invoices and financial audit.
                    // UserEnrollmentService excludes the refunded purchase by its
                    // transaction/refund timeline and still honours a later repurchase.

                    // 4. Mark certificates as revoked
                    $certificates = \App\Models\Course\CourseCertificate::where('user_id', $refundRequest->user_id)
                        ->where('course_id', $refundRequest->course_id)
                        ->get();

                    foreach ($certificates as $certificate) {
                        $certData = [
                            'status'         => 'revoked',
                            'revoked_at'     => Carbon::now(),
                            'revoked_reason' => 'Course refunded',
                            'revoked_by'     => $admin?->id,
                        ];
                        if (\Illuminate\Support\Facades\Schema::hasColumn('course_certificates', 'is_valid')) {
                            $certData['is_valid'] = false;
                        }
                        $certificate->update($certData);
                    }

                    $refundRequest->update([
                        'status' => 'approved',
                        'admin_notes' => $request->admin_notes,
                        'admin_receipt' => null,
                        'processed_at' => Carbon::now(),
                        'processed_by' => $admin?->id,
                    ]);

                    // Add media URLs to response
                    $refundRequest->user_media_url = $refundRequest->user_media
                        ? FileService::getFileUrl($refundRequest->user_media)
                        : null;
                    $refundRequest->admin_receipt_url = null;

                    AuditLogService::log(
                        action: 'refund_approved',
                        target: $refundRequest,
                        summary: "Approved refund request #{$refundRequest->id} for user #{$refundRequest->user_id}",
                        details: [
                            'refund_request_id' => $refundRequest->id,
                            'user_id' => $refundRequest->user_id,
                            'course_id' => $refundRequest->course_id,
                            'amount_egp' => $refundAmountEgp,
                        ]
                    );

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

                    AuditLogService::log(
                        action: 'refund_rejected',
                        target: $refundRequest,
                        summary: "Rejected refund request #{$refundRequest->id} for user #{$refundRequest->user_id}",
                        details: [
                            'refund_request_id' => $refundRequest->id,
                            'user_id' => $refundRequest->user_id,
                            'course_id' => $refundRequest->course_id,
                        ]
                    );

                    return ApiResponseService::successResponse('Refund request rejected');
                }
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Get refund settings (for admin)
     */
    public function getRefundSettings()
    {
        $this->ensureAdminPermission('finance-list');

        try {
            $settings = Setting::whereIn('name', ['refund_enabled', 'refund_period_days'])->get();

            $formattedSettings = [];
            foreach ($settings as $setting) {
                $formattedSettings[$setting->name] = $setting->value;
            }

            return ApiResponseService::successResponse('Refund settings retrieved successfully', $formattedSettings);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Update refund settings (for admin)
     */
    public function updateRefundSettings(Request $request)
    {
        $this->ensureAdminPermission('finance-edit');

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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }

    /**
     * Get all refund requests (for admin)
     */
    public function getAllRefunds(Request $request)
    {
        $this->ensureAdminPermission('finance-list');

        try {
            $validated = $request->validate([
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'status' => 'nullable|string|in:pending,approved,rejected',
                'search' => 'nullable|string|max:255',
            ]);
            $query = RefundRequest::with(['user', 'course', 'transaction', 'processedByUser']);

            // Filter by status if provided
            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            // Search by user name or course title
            if (!empty($validated['search'])) {
                $search = $validated['search'];
                $query->where(static function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->whereHas('user', static function ($q) use ($search): void {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('course', static function ($q) use ($search): void {
                            $q->where('title', 'like', "%{$search}%");
                        });
                });
            }

            $refunds = $query->orderBy('created_at', 'desc')->paginate((int) ($validated['per_page'] ?? 15));

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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Something went wrong: ' . $e->getMessage());
        }
    }
}
