<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Instructor;
use App\Models\User;
use App\Models\WalletHistory;
use App\Models\WithdrawalRequest;
use App\Notifications\CommissionPaidNotification;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FinanceApiController extends Controller
{
    /**
     * Get admin finance dashboard data
     */
    public function getFinanceDashboard(Request $request)
    {
        try {
            // Total commissions summary
            $totalCommissions = Commission::sum('admin_commission_amount');
            $totalInstructorCommissions = Commission::sum('instructor_commission_amount');
            $totalPaidCommissions = Commission::where('status', 'paid')->sum('admin_commission_amount');
            $totalPendingCommissions = Commission::where('status', 'pending')->sum('admin_commission_amount');

            // Monthly data (last 12 months)
            $monthlyData = Commission::selectRaw('
                    YEAR(created_at) as year,
                    MONTH(created_at) as month,
                    SUM(admin_commission_amount) as admin_total,
                    SUM(instructor_commission_amount) as instructor_total,
                    COUNT(*) as commission_count
                ')
                ->where('created_at', '>=', now()->subMonths(12))
                ->groupBy('year', 'month')
                ->orderBy('year', 'desc')
                ->orderBy('month', 'desc')
                ->get();

            // Top earning instructors
            $topInstructors = Commission::select('instructor_id')
                ->selectRaw('SUM(instructor_commission_amount) as total_earnings')
                ->with('instructor:id,name,email')
                ->where('status', 'paid')
                ->groupBy('instructor_id')
                ->orderBy('total_earnings', 'desc')
                ->limit(10)
                ->get();

            // Recent transactions
            $recentTransactions = WalletHistory::where('transaction_type', 'commission')
                ->with('user:id,name,email')
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get();

            $dashboardData = [
                'summary' => [
                    'total_admin_commissions' => $totalCommissions,
                    'total_instructor_commissions' => $totalInstructorCommissions,
                    'total_paid_commissions' => $totalPaidCommissions,
                    'total_pending_commissions' => $totalPendingCommissions,
                ],
                'monthly_data' => $monthlyData,
                'top_instructors' => $topInstructors,
                'recent_transactions' => $recentTransactions,
            ];

            return ApiResponseService::successResponse('Finance dashboard data retrieved successfully', $dashboardData);
        } catch (\Throwable) {
            return ApiResponseService::errorResponse('Failed to retrieve finance dashboard data');
        }
    }

    /**
     * Get all commissions with filters
     */
    public function getCommissions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'nullable|in:pending,paid,cancelled',
                'instructor_id' => 'nullable|exists:users,id',
                'course_id' => 'nullable|exists:courses,id',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Commission::with(['instructor:id,name,email', 'course:id,title', 'order:id,order_number']);

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('instructor_id')) {
                $query->where('instructor_id', $request->instructor_id);
            }

            if ($request->filled('course_id')) {
                $query->where('course_id', $request->course_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $perPage = $request->per_page ?? 15;
            $commissions = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return ApiResponseService::successResponse('Commissions retrieved successfully', $commissions);
        } catch (\Throwable) {
            return ApiResponseService::errorResponse('Failed to retrieve commissions');
        }
    }

    /**
     * Get instructor earnings summary
     */
    public function getInstructorEarnings(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'instructor_id' => 'nullable|exists:users,id',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = Commission::select('instructor_id')
                ->selectRaw('
                    SUM(instructor_commission_amount) as total_earnings,
                    SUM(CASE WHEN status = "paid" THEN instructor_commission_amount ELSE 0 END) as paid_earnings,
                    SUM(CASE WHEN status = "pending" THEN instructor_commission_amount ELSE 0 END) as pending_earnings,
                    COUNT(*) as total_commissions
                ')
                ->with('instructor:id,name,email,wallet_balance');

            if ($request->filled('instructor_id')) {
                $query->where('instructor_id', $request->instructor_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $earnings = $query->groupBy('instructor_id')->orderBy('total_earnings', 'desc')->get();

            return ApiResponseService::successResponse('Instructor earnings retrieved successfully', $earnings);
        } catch (\Throwable) {
            return ApiResponseService::errorResponse('Failed to retrieve instructor earnings');
        }
    }

    /**
     * Get wallet transactions
     */
    public function getWalletTransactions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'nullable|exists:users,id',
                'transaction_type' => 'nullable|in:refund,purchase,commission,withdrawal,adjustment',
                'type' => 'nullable|in:credit,debit',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = WalletHistory::with('user:id,name,email');

            // Apply filters
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('transaction_type')) {
                $query->where('transaction_type', $request->transaction_type);
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $perPage = $request->per_page ?? 15;
            $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return ApiResponseService::successResponse('Wallet transactions retrieved successfully', $transactions);
        } catch (\Throwable) {
            return ApiResponseService::errorResponse('Failed to retrieve wallet transactions');
        }
    }

    /**
     * Manually process pending commission (admin only)
     */
    public function processCommission(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'commission_id' => 'required|exists:commissions,id',
                'action' => 'required|in:approve,reject',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $commission = Commission::findOrFail($request->commission_id);

            if ($commission->status !== 'pending') {
                return ApiResponseService::validationError('Commission is already processed');
            }

            DB::beginTransaction();

            if ($request->action === 'approve') {
                // Credit instructor wallet
                WalletService::creditWallet(
                    $commission->instructor_id,
                    $commission->instructor_commission_amount,
                    'commission',
                    "Manual commission approval for course: {$commission->course->title} (Order #{$commission->order->order_number})",
                    $commission->id,
                    \App\Models\Commission::class,
                );

                $commission->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                // Send notification to instructor
                $instructor = User::find($commission->instructor_id);
                if ($instructor) {
                    $instructor->notify(new CommissionPaidNotification($commission));
                }

                $message = 'Commission approved and credited to instructor wallet successfully';
            } else {
                $commission->update([
                    'status' => 'cancelled',
                ]);

                $message = 'Commission rejected successfully';
            }

            DB::commit();

            return ApiResponseService::successResponse($message, $commission->fresh());
        } catch (\Throwable) {
            DB::rollBack();
            return ApiResponseService::errorResponse('Failed to process commission');
        }
    }

    /**
     * Get finance reports
     */
    public function getFinanceReports(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'report_type' => 'required|in:daily,weekly,monthly,yearly',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $reportType = $request->report_type;
            $dateFrom = $request->date_from ?? now()->subDays(30);
            $dateTo = $request->date_to ?? now();

            $format = match ($reportType) {
                'daily' => '%Y-%m-%d',
                'weekly' => '%Y-%u',
                'monthly' => '%Y-%m',
                'yearly' => '%Y',
            };

            $report = Commission::selectRaw("
                    DATE_FORMAT(created_at, '{$format}') as period,
                    SUM(admin_commission_amount) as admin_total,
                    SUM(instructor_commission_amount) as instructor_total,
                    COUNT(*) as commission_count,
                    COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count
                ")
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->groupBy('period')
                ->orderBy('period', 'desc')
                ->get();

            return ApiResponseService::successResponse('Finance report generated successfully', [
                'report_type' => $reportType,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo,
                ],
                'data' => $report,
            ]);
        } catch (\Throwable) {
            return ApiResponseService::errorResponse('Failed to generate finance report');
        }
    }

    /**
     * Create withdrawal request (instructor only)
     */
    public function createWithdrawalRequest(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1|max:999999.99',
                'payment_method' => 'required|string|in:bank_transfer',
                'payment_details' => 'required|array',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            // Check if user is authenticated
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
            }

            // Check if user is an approved instructor
            $instructor = Instructor::where('user_id', $user->id)->where('status', 'approved')->first();

            if (!$instructor) {
                return ApiResponseService::errorResponse(
                    'Only approved instructors can create withdrawal requests.',
                    [],
                    403,
                );
            }

            $amount = $request->amount;

            // Check if user has sufficient wallet balance
            if ($user->wallet_balance < $amount) {
                $currencySymbol = HelperService::systemSettings('currency_symbol') ?? '$';
                return ApiResponseService::validationError('Insufficient wallet balance. Available: '
                . $currencySymbol
                . number_format($user->wallet_balance, 2));
            }

            // Check if user has any pending withdrawal requests
            $pendingRequest = WithdrawalRequest::where('user_id', $user->id)
                ->whereIn('status', ['pending', 'processing'])
                ->first();

            if ($pendingRequest) {
                return ApiResponseService::validationError(
                    'You already have a pending withdrawal request. Please wait for it to be processed.',
                );
            }

            // Validate payment details based on method
            $paymentDetails = $this->validatePaymentDetails($request->payment_method, $request->payment_details);
            if (!$paymentDetails['valid']) {
                return ApiResponseService::validationError($paymentDetails['message']);
            }

            DB::beginTransaction();

            // Determine entry type (instructor)
            $entryType = 'instructor';

            // Create withdrawal request
            $withdrawalRequest = WithdrawalRequest::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'entry_type' => $entryType,
                'payment_method' => $request->payment_method,
                'payment_details' => $request->payment_details,
                'notes' => $request->notes,
                'status' => 'pending',
            ]);

            // Deduct amount from wallet balance directly (without creating history entry)
            // History entry will be created only when withdrawal is approved/rejected
            $user->wallet_balance -= $amount;
            $user->save();
            $user->refresh(); // Refresh to get updated balance

            DB::commit();

            return ApiResponseService::successResponse('Withdrawal request created successfully', [
                'withdrawal_request' => $withdrawalRequest->load('user:id,name,email'),
                'remaining_balance' => (float) $user->wallet_balance,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('Failed to create withdrawal request: ' . $e->getMessage());
        }
    }

    /**
     * Get withdrawal requests for instructor
     */
    public function getWithdrawalRequests(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'nullable|in:pending,approved,rejected,processing,completed',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            // Check if user is authenticated
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
            }

            // Check if user is an approved instructor
            $instructor = Instructor::where('user_id', $user->id)->where('status', 'approved')->first();

            if (!$instructor) {
                return ApiResponseService::errorResponse(
                    'Only approved instructors can view withdrawal requests.',
                    [],
                    403,
                );
            }
            $query = WithdrawalRequest::where('user_id', $user->id);

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $perPage = $request->per_page ?? 15;
            $withdrawalRequests = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Format the response
            $formattedRequests = $withdrawalRequests->map(static fn($request) => [
                'id' => $request->id,
                'amount' => $request->amount,
                'status' => $request->status,
                'status_label' => ucfirst((string) $request->status),
                'payment_method' => $request->payment_method,
                'payment_method_label' => ucwords(str_replace('_', ' ', $request->payment_method)),
                'notes' => $request->notes,
                'admin_notes' => $request->admin_notes,
                'created_at' => $request->created_at,
                'created_at_formatted' => $request->created_at->format('Y-m-d H:i:s'),
                'time_ago' => $request->created_at->diffForHumans(),
                'processed_at' => $request->processed_at,
                'processed_at_formatted' => $request->processed_at
                    ? $request->processed_at->format('Y-m-d H:i:s')
                    : null,
                'processed_by' => $request->processedBy
                    ? [
                        'id' => $request->processedBy->id,
                        'name' => $request->processedBy->name,
                    ] : null,
            ]);

            return ApiResponseService::successResponse('Withdrawal requests retrieved successfully', [
                'withdrawal_requests' => $formattedRequests,
                'pagination' => [
                    'current_page' => $withdrawalRequests->currentPage(),
                    'last_page' => $withdrawalRequests->lastPage(),
                    'per_page' => $withdrawalRequests->perPage(),
                    'total' => $withdrawalRequests->total(),
                    'from' => $withdrawalRequests->firstItem(),
                    'to' => $withdrawalRequests->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve withdrawal requests: ' . $e->getMessage());
        }
    }

    /**
     * Get instructor wallet summary
     */
    public function getWalletSummary(Request $request)
    {
        try {
            $user = Auth::user();

            // Check if user is authenticated
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
            }

            // Check if user is an approved instructor
            $instructor = Instructor::where('user_id', $user->id)->where('status', 'approved')->first();

            if (!$instructor) {
                return ApiResponseService::errorResponse('Only approved instructors can view wallet summary.', [], 403);
            }

            // Get wallet balance
            $walletBalance = $user->wallet_balance;

            // Get total earnings
            $totalEarnings = Commission::where('instructor_id', $user->id)->where('status', 'paid')->sum(
                'instructor_commission_amount',
            );

            // Get pending earnings
            $pendingEarnings = Commission::where('instructor_id', $user->id)->where('status', 'pending')->sum(
                'instructor_commission_amount',
            );

            // Get total withdrawals
            $totalWithdrawals = WithdrawalRequest::where('user_id', $user->id)->whereIn('status', [
                'approved',
                'completed',
            ])->sum('amount');

            // Get pending withdrawals
            $pendingWithdrawals = WithdrawalRequest::where('user_id', $user->id)->whereIn('status', [
                'pending',
                'processing',
            ])->sum('amount');

            // Get recent transactions
            $recentTransactions = WalletHistory::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(static fn($transaction) => [
                    'id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'type' => $transaction->type,
                    'transaction_type' => $transaction->transaction_type,
                    'description' => $transaction->description,
                    'balance_after' => $transaction->balance_after,
                    'created_at' => $transaction->created_at,
                    'created_at_formatted' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'time_ago' => $transaction->created_at->diffForHumans(),
                ]);

            $summary = [
                'wallet_balance' => $walletBalance,
                'total_earnings' => $totalEarnings,
                'pending_earnings' => $pendingEarnings,
                'total_withdrawals' => $totalWithdrawals,
                'pending_withdrawals' => $pendingWithdrawals,
                'available_for_withdrawal' => $walletBalance - $pendingWithdrawals,
                'recent_transactions' => $recentTransactions,
            ];

            return ApiResponseService::successResponse('Wallet summary retrieved successfully', $summary);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve wallet summary: ' . $e->getMessage());
        }
    }

    /**
     * Validate payment details based on payment method
     */
    private function validatePaymentDetails($paymentMethod, $paymentDetails)
    {
        switch ($paymentMethod) {
            case 'bank_transfer':
                $required = ['account_holder_name', 'account_number', 'bank_name', 'ifsc_code'];
                break;
            case 'paypal':
                $required = ['paypal_email'];
                break;
            case 'stripe':
                $required = ['stripe_account_id'];
                break;
            case 'razorpay':
                $required = ['razorpay_account_id'];
                break;
            default:
                return ['valid' => false, 'message' => 'Invalid payment method'];
        }

        foreach ($required as $field) {
            if (!(!isset($paymentDetails[$field]) || empty($paymentDetails[$field]))) {
                continue;
            }

            return ['valid' => false, 'message' => "Missing required field: {$field}"];
        }

        return ['valid' => true];
    }

    /**
     * Get all withdrawal requests for admin
     */
    public function getAdminWithdrawalRequests(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'nullable|in:pending,approved,rejected,processing,completed',
                'user_id' => 'nullable|exists:users,id',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            // Check if user is admin
            if (!$user || !$user->is_admin) {
                return ApiResponseService::errorResponse('Only admins can view withdrawal requests.', [], 403);
            }

            $query = WithdrawalRequest::with(['user:id,name,email', 'processedBy:id,name']);

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $perPage = $request->per_page ?? 15;
            $withdrawalRequests = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Format the response
            $formattedRequests = $withdrawalRequests->map(static fn($request) => [
                'id' => $request->id,
                'amount' => $request->amount,
                'status' => $request->status,
                'status_label' => ucfirst((string) $request->status),
                'payment_method' => $request->payment_method,
                'payment_method_label' => ucwords(str_replace('_', ' ', $request->payment_method)),
                'payment_details' => $request->payment_details,
                'notes' => $request->notes,
                'admin_notes' => $request->admin_notes,
                'created_at' => $request->created_at,
                'created_at_formatted' => $request->created_at->format('Y-m-d H:i:s'),
                'time_ago' => $request->created_at->diffForHumans(),
                'processed_at' => $request->processed_at,
                'processed_at_formatted' => $request->processed_at
                    ? $request->processed_at->format('Y-m-d H:i:s')
                    : null,
                'user' => [
                    'id' => $request->user->id,
                    'name' => $request->user->name,
                    'email' => $request->user->email,
                ],
                'processed_by' => $request->processedBy
                    ? [
                        'id' => $request->processedBy->id,
                        'name' => $request->processedBy->name,
                    ] : null,
            ]);

            // Get summary statistics
            $summary = [
                'total_requests' => WithdrawalRequest::count(),
                'pending_requests' => WithdrawalRequest::where('status', 'pending')->count(),
                'approved_requests' => WithdrawalRequest::where('status', 'approved')->count(),
                'rejected_requests' => WithdrawalRequest::where('status', 'rejected')->count(),
                'processing_requests' => WithdrawalRequest::where('status', 'processing')->count(),
                'completed_requests' => WithdrawalRequest::where('status', 'completed')->count(),
                'total_amount_pending' => WithdrawalRequest::where('status', 'pending')->sum('amount'),
                'total_amount_approved' => WithdrawalRequest::where('status', 'approved')->sum('amount'),
                'total_amount_completed' => WithdrawalRequest::where('status', 'completed')->sum('amount'),
            ];

            return ApiResponseService::successResponse('Withdrawal requests retrieved successfully', [
                'withdrawal_requests' => $formattedRequests,
                'summary' => $summary,
                'pagination' => [
                    'current_page' => $withdrawalRequests->currentPage(),
                    'last_page' => $withdrawalRequests->lastPage(),
                    'per_page' => $withdrawalRequests->perPage(),
                    'total' => $withdrawalRequests->total(),
                    'from' => $withdrawalRequests->firstItem(),
                    'to' => $withdrawalRequests->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve withdrawal requests: ' . $e->getMessage());
        }
    }

    /**
     * Update withdrawal request status (admin only)
     */
    public function updateWithdrawalRequestStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'withdrawal_request_id' => 'required|exists:withdrawal_requests,id',
                'status' => 'required|in:pending,approved,rejected,processing,completed',
                'admin_notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            // Check if user is admin
            if (!$user || !$user->is_admin) {
                return ApiResponseService::errorResponse('Only admins can update withdrawal request status.', [], 403);
            }

            $withdrawalRequest = WithdrawalRequest::with('user')->findOrFail($request->withdrawal_request_id);
            $oldStatus = $withdrawalRequest->status;
            $newStatus = $request->status;

            // Check if status change is valid
            if ($oldStatus === $newStatus) {
                return ApiResponseService::validationError('Status is already set to ' . $newStatus);
            }

            DB::beginTransaction();

            // Update withdrawal request
            $withdrawalRequest->update([
                'status' => $newStatus,
                'admin_notes' => $request->admin_notes,
                'processed_at' => now(),
                'processed_by' => $user->id,
            ]);

            // Handle wallet operations based on status change
            if ($oldStatus === 'pending' && $newStatus === 'rejected') {
                // Refund the amount back to user's wallet
                WalletService::creditWallet(
                    $withdrawalRequest->user_id,
                    $withdrawalRequest->amount,
                    'withdrawal',
                    "Withdrawal request #{$withdrawalRequest->id} rejected - Amount refunded",
                    $withdrawalRequest->id,
                    \App\Models\WithdrawalRequest::class,
                );
            }

            DB::commit();

            return ApiResponseService::successResponse('Withdrawal request status updated successfully', [
                'withdrawal_request' => $withdrawalRequest->fresh(['user:id,name,email', 'processedBy:id,name']),
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('Failed to update withdrawal request status: ' . $e->getMessage());
        }
    }

    /**
     * Get withdrawal request details for admin
     */
    public function getWithdrawalRequestDetails(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'withdrawal_request_id' => 'required|exists:withdrawal_requests,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            // Check if user is admin
            if (!$user || !$user->is_admin) {
                return ApiResponseService::errorResponse('Only admins can view withdrawal request details.', [], 403);
            }

            $withdrawalRequest = WithdrawalRequest::with([
                'user:id,name,email,wallet_balance',
                'processedBy:id,name,email',
            ])->findOrFail($request->withdrawal_request_id);

            // Get user's wallet history related to this withdrawal
            $walletHistory = WalletHistory::where('user_id', $withdrawalRequest->user_id)
                ->where('reference_type', \App\Models\WithdrawalRequest::class)
                ->where('reference_id', $withdrawalRequest->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $response = [
                'withdrawal_request' => [
                    'id' => $withdrawalRequest->id,
                    'amount' => $withdrawalRequest->amount,
                    'status' => $withdrawalRequest->status,
                    'status_label' => ucfirst((string) $withdrawalRequest->status),
                    'payment_method' => $withdrawalRequest->payment_method,
                    'payment_method_label' => ucwords(str_replace('_', ' ', $withdrawalRequest->payment_method)),
                    'payment_details' => $withdrawalRequest->payment_details,
                    'notes' => $withdrawalRequest->notes,
                    'admin_notes' => $withdrawalRequest->admin_notes,
                    'created_at' => $withdrawalRequest->created_at,
                    'created_at_formatted' => $withdrawalRequest->created_at->format('Y-m-d H:i:s'),
                    'time_ago' => $withdrawalRequest->created_at->diffForHumans(),
                    'processed_at' => $withdrawalRequest->processed_at,
                    'processed_at_formatted' => $withdrawalRequest->processed_at
                        ? $withdrawalRequest->processed_at->format('Y-m-d H:i:s')
                        : null,
                ],
                'user' => [
                    'id' => $withdrawalRequest->user->id,
                    'name' => $withdrawalRequest->user->name,
                    'email' => $withdrawalRequest->user->email,
                    'wallet_balance' => $withdrawalRequest->user->wallet_balance,
                ],
                'processed_by' => $withdrawalRequest->processedBy
                    ? [
                        'id' => $withdrawalRequest->processedBy->id,
                        'name' => $withdrawalRequest->processedBy->name,
                        'email' => $withdrawalRequest->processedBy->email,
                    ] : null,
                'wallet_history' => $walletHistory->map(static fn($transaction) => [
                    'id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'type' => $transaction->type,
                    'transaction_type' => $transaction->transaction_type,
                    'description' => $transaction->description,
                    'balance_before' => $transaction->balance_before,
                    'balance_after' => $transaction->balance_after,
                    'created_at' => $transaction->created_at,
                    'created_at_formatted' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'time_ago' => $transaction->created_at->diffForHumans(),
                ]),
            ];

            return ApiResponseService::successResponse('Withdrawal request details retrieved successfully', $response);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve withdrawal request details: '
            . $e->getMessage());
        }
    }
}
