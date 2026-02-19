<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WalletHistory;
use App\Models\WithdrawalRequest;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletApiController extends Controller
{
    /**
     * Get user's wallet balance and summary
     */
    public function getWalletSummary(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
            }

            // Get wallet balance
            $walletBalance = $user->wallet_balance ?? 0;

            // Get total credits (sum of all credit transactions)
            $totalCredits = WalletHistory::where('user_id', $user->id)->where('type', 'credit')->sum('amount');

            // Get total debits (sum of all debit transactions)
            $totalDebits = WalletHistory::where('user_id', $user->id)->where('type', 'debit')->sum('amount');

            // Get pending withdrawals
            $pendingWithdrawals = WithdrawalRequest::where('user_id', $user->id)->whereIn('status', [
                'pending',
                'processing',
            ])->sum('amount');

            // Get total withdrawals (approved/completed)
            $totalWithdrawals = WithdrawalRequest::where('user_id', $user->id)->whereIn('status', [
                'approved',
                'completed',
            ])->sum('amount');

            // Get recent transactions (last 5)
            $recentTransactions = WalletHistory::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(static fn($transaction) => [
                    'id' => $transaction->id,
                    'amount' => (float) $transaction->amount,
                    'type' => $transaction->type,
                    'transaction_type' => $transaction->transaction_type,
                    'description' => $transaction->description,
                    'balance_before' => (float) $transaction->balance_before,
                    'balance_after' => (float) $transaction->balance_after,
                    'created_at' => $transaction->created_at,
                    'created_at_formatted' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'time_ago' => $transaction->created_at->diffForHumans(),
                ]);

            $summary = [
                'wallet_balance' => (float) $walletBalance,
                'total_credits' => (float) $totalCredits,
                'total_debits' => (float) $totalDebits,
                'total_withdrawals' => (float) $totalWithdrawals,
                'pending_withdrawals' => (float) $pendingWithdrawals,
                'available_for_withdrawal' => (float) max(0, $walletBalance - $pendingWithdrawals),
                'recent_transactions' => $recentTransactions,
            ];

            return ApiResponseService::successResponse('Wallet summary retrieved successfully', $summary);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve wallet summary: ' . $e->getMessage());
        }
    }

    /**
     * Initiate wallet top-up via Kashier (T095).
     * Returns checkout URL for redirect.
     */
    public function topUp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1|max:50000',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Authentication required.');
        }

        $amount = (float) $request->amount;

        try {
            $kashier = app(\App\Services\Payment\KashierCheckoutService::class);
            $result = $kashier->createWalletTopUpSession($user, $amount);

            return ApiResponseService::successResponse('Redirect to payment', [
                'checkout_url' => $result['url'],
                'order_id' => $result['order_id'],
                'amount' => $result['amount'],
                'currency' => $result['currency'],
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to create checkout: ' . $e->getMessage());
        }
    }

    /**
     * Get user's wallet history with pagination and filters
     */
    public function getWalletHistory(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'transaction_type' => 'nullable|in:refund,purchase,commission,withdrawal,adjustment,reward',
                'type' => 'nullable|in:credit,debit',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
            }

            $query = WalletHistory::where('user_id', $user->id);

            // Apply filters
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
            $currentPage = $request->page ?? 1;

            // Get ALL transactions first (without pagination) to merge with pending withdrawals
            // Eager load reference relationships based on reference_type
            $allTransactionsQuery = $query
                ->with([
                    'reference' => static function ($morphTo): void {
                        $morphTo->morphWith([
                            \App\Models\Order::class => ['orderCourses.course', 'paymentTransaction'],
                            \App\Models\RefundRequest::class => ['course', 'transaction.order'],
                            \App\Models\WithdrawalRequest::class => [],
                            \App\Models\Commission::class => ['course', 'order'], // Added Commission with course and order
                        ]);
                    },
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            // Check if user has pending withdrawal request
            $hasPendingWithdrawal = WithdrawalRequest::where('user_id', $user->id)
                ->where('status', 'pending')
                ->exists();

            // Get withdrawal request IDs that already have WalletHistory entries
            $withdrawalIdsWithHistory = WalletHistory::where('user_id', $user->id)
                ->where('reference_type', \App\Models\WithdrawalRequest::class)
                ->whereNotNull('reference_id')
                ->pluck('reference_id')
                ->unique()
                ->toArray();

            // Get pending withdrawal requests to append to history
            $pendingWithdrawals = WithdrawalRequest::where('user_id', $user->id)
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->get();

            // Get approved/processing/completed withdrawal requests that don't have WalletHistory entries
            $approvedWithdrawals = WithdrawalRequest::where('user_id', $user->id)
                ->whereIn('status', ['approved', 'processing', 'completed'])
                ->whereNotIn('id', $withdrawalIdsWithHistory)
                ->orderBy('created_at', 'desc')
                ->get();

            // Transform the data to include additional fields
            $allTransactionsQuery->transform(static function ($transaction) {
                $courseName = null;
                $transactionId = null;
                $orderNumber = null;
                $status = null;
                $paymentMethod = null;
                $paymentDetails = null;

                // Get data based on reference type
                if ($transaction->reference) {
                    $reference = $transaction->reference;
                    $referenceType = $transaction->reference_type;

                    // Handle Order reference
                    if ($referenceType === \App\Models\Order::class) {
                        // Get course name from first order course
                        if ($reference->orderCourses && $reference->orderCourses->isNotEmpty()) {
                            $firstCourse = $reference->orderCourses->first()->course;
                            $courseName = $firstCourse ? $firstCourse->title : null;
                        }

                        // Get transaction_id from Transaction model (via order relationship)
                        // Transaction belongsTo Order, so query Transaction where order_id matches
                        $orderTransaction = \App\Models\Transaction::where('order_id', $reference->id)->first();
                        if ($orderTransaction) {
                            $transactionId = $orderTransaction->transaction_id;
                        }

                        // Get order_number from Order
                        $orderNumber = $reference->order_number;

                        $status = $reference->status;
                        $paymentMethod = $reference->payment_method;
                    }
                    // Handle RefundRequest reference
                    elseif ($referenceType === \App\Models\RefundRequest::class) {
                        $courseName = $reference->course ? $reference->course->title : null;

                        // Get transaction_id from Transaction model (via RefundRequest -> Transaction relationship)
                        if ($reference->transaction) {
                            $transactionId = $reference->transaction->transaction_id;
                        }

                        // Get order_number from Transaction -> Order relationship
                        if ($reference->transaction && $reference->transaction->order) {
                            $orderNumber = $reference->transaction->order->order_number;
                        }

                        $status = $reference->status;
                    }
                    // Handle WithdrawalRequest reference
                    elseif ($referenceType === \App\Models\WithdrawalRequest::class) {
                        $status = $reference->status;
                        $paymentMethod = $reference->payment_method;
                        $paymentDetails = $reference->payment_details;
                    }
                    // Handle Commission reference
                    elseif ($referenceType === \App\Models\Commission::class) {
                        // Get course name from Commission
                        $courseName = $reference->course ? $reference->course->title : null;

                        // Get order_number from Commission -> Order relationship
                        if ($reference->order) {
                            $orderNumber = $reference->order->order_number;
                            // Get transaction_id from Order's Transaction
                            $orderTransaction = \App\Models\Transaction::where(
                                'order_id',
                                $reference->order->id,
                            )->first();
                            if ($orderTransaction) {
                                $transactionId = $orderTransaction->transaction_id;
                            }
                        }

                        // Get status from Commission
                        $status = $reference->status;
                    }
                }

                // Add new fields to the transaction object
                $transaction->course_name = $courseName;
                $transaction->transaction_id = $transactionId;
                $transaction->order_number = $orderNumber;
                $transaction->transaction_date = $transaction->created_at->format('Y-m-d H:i:s');
                $transaction->status = $status;
                $transaction->payment_method = $paymentMethod;
                $transaction->payment_details = $paymentDetails;
                $transaction->type_label = ucfirst((string) $transaction->type);
                $transaction->transaction_type_label = ucwords(str_replace('_', ' ', $transaction->transaction_type));
                $transaction->created_at_formatted = $transaction->created_at->format('Y-m-d H:i:s');
                $transaction->time_ago = $transaction->created_at->diffForHumans();

                // Ensure numeric values are floats
                $transaction->amount = (float) $transaction->amount;
                $transaction->balance_before = (float) $transaction->balance_before;
                $transaction->balance_after = (float) $transaction->balance_after;

                return $transaction;
            });

            // Format pending withdrawal requests as wallet history entries
            $pendingWithdrawalEntries = $pendingWithdrawals->map(static function ($withdrawal) use ($user) {
                // Calculate balance: when withdrawal is created, amount is deducted from wallet
                // So balance_before = current_balance + withdrawal_amount
                // balance_after = current_balance
                $balanceAfter = (float) $user->wallet_balance;
                $balanceBefore = (float) ($balanceAfter + $withdrawal->amount);

                return [
                    'id' => (int) $withdrawal->id, // Use withdrawal ID directly
                    'user_id' => $user->id,
                    'amount' => (float) abs($withdrawal->amount), // Positive amount
                    'type' => 'debit',
                    'transaction_type' => 'withdrawal',
                    'entry_type' => $withdrawal->entry_type ?? 'user',
                    'reference_id' => $withdrawal->id,
                    'reference_type' => \App\Models\WithdrawalRequest::class,
                    'description' => 'Withdrawal Request - Pending',
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'created_at' => $withdrawal->created_at->toDateTimeString(),
                    'updated_at' => $withdrawal->updated_at->toDateTimeString(),
                    // Additional fields matching wallet history format
                    'course_name' => null,
                    'transaction_id' => null,
                    'order_number' => null,
                    'transaction_date' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'status' => 'pending',
                    'payment_method' => $withdrawal->payment_method,
                    'payment_details' => $withdrawal->payment_details,
                    'type_label' => 'Debit',
                    'transaction_type_label' => 'Withdrawal',
                    'created_at_formatted' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'time_ago' => $withdrawal->created_at->diffForHumans(),
                ];
            })->toArray();

            // Format approved/processing/completed withdrawal requests as wallet history entries
            $approvedWithdrawalEntries = $approvedWithdrawals->map(static function ($withdrawal) use ($user) {
                // Calculate balance: when withdrawal is created, amount is deducted from wallet
                // So balance_before = current_balance + withdrawal_amount
                // balance_after = current_balance
                $balanceAfter = (float) $user->wallet_balance;
                $balanceBefore = (float) ($balanceAfter + $withdrawal->amount);

                $statusLabel = ucfirst((string) $withdrawal->status);
                $description = "Withdrawal Request - {$statusLabel}";

                return [
                    'id' => (int) $withdrawal->id, // Use withdrawal ID directly
                    'user_id' => $user->id,
                    'amount' => (float) abs($withdrawal->amount), // Positive amount
                    'type' => 'debit',
                    'transaction_type' => 'withdrawal',
                    'entry_type' => $withdrawal->entry_type ?? 'user',
                    'reference_id' => $withdrawal->id,
                    'reference_type' => \App\Models\WithdrawalRequest::class,
                    'description' => $description,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'created_at' => $withdrawal->created_at->toDateTimeString(),
                    'updated_at' => $withdrawal->updated_at->toDateTimeString(),
                    // Additional fields matching wallet history format
                    'course_name' => null,
                    'transaction_id' => null,
                    'order_number' => null,
                    'transaction_date' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'status' => $withdrawal->status,
                    'payment_method' => $withdrawal->payment_method,
                    'payment_details' => $withdrawal->payment_details,
                    'type_label' => 'Debit',
                    'transaction_type_label' => 'Withdrawal',
                    'created_at_formatted' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                    'time_ago' => $withdrawal->created_at->diffForHumans(),
                ];
            })->toArray();

            // Convert transactions to arrays using json encode/decode to handle all attributes
            $transactionsArray = $allTransactionsQuery->map(static function ($transaction) {
                // Use json encode/decode to convert object to array, preserving all attributes
                $json = json_encode($transaction);
                $arr = json_decode($json, true);
                // Ensure created_at is properly formatted as string
                if (isset($arr['created_at']) && is_array($arr['created_at'])) {
                    // If it's a Carbon date array, convert to string
                    if (isset($arr['created_at']['date'])) {
                        $arr['created_at'] = $arr['created_at']['date'];
                    }
                } elseif (isset($transaction->created_at) && is_object($transaction->created_at)) {
                    $arr['created_at'] = $transaction->created_at->toDateTimeString();
                }
                return $arr;
            })->toArray();

            // Merge and sort by created_at descending using collection
            $allTransactions = collect($transactionsArray)
                ->merge($pendingWithdrawalEntries)
                ->merge($approvedWithdrawalEntries)
                ->sortByDesc(static function ($item) {
                    if (isset($item['created_at'])) {
                        return is_string($item['created_at']) ? strtotime($item['created_at']) : 0;
                    }
                    return 0;
                })
                ->values()
                ->toArray();

            // Get pagination parameters
            $originalTotal = count($transactionsArray);
            $pendingCount = $pendingWithdrawals->count();
            $approvedCount = $approvedWithdrawals->count();
            $newTotal = $originalTotal + $pendingCount + $approvedCount;

            // Paginate the merged array
            $offset = ($currentPage - 1) * $perPage;
            $paginatedData = array_slice($allTransactions, $offset, $perPage);

            // Create new paginated collection with merged data
            $paginatedTransactions = new \Illuminate\Pagination\LengthAwarePaginator(
                $paginatedData,
                $newTotal,
                $perPage,
                $currentPage,
                [
                    'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
                    'pageName' => 'page',
                ],
            );

            // Convert paginated collection to array and add is_withdrawal_request_pending field
            $responseData = $paginatedTransactions->toArray();
            $responseData['is_withdrawal_request_pending'] = $hasPendingWithdrawal;

            return ApiResponseService::successResponse('Wallet history retrieved successfully', $responseData);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve wallet history: ' . $e->getMessage());
        }
    }

    /**
     * Create withdrawal request for user
     */
    public function createWithdrawalRequest(Request $request)
    {
        try {
            // Handle payment_details - Laravel should auto-parse nested arrays like payment_details[field]
            // Try multiple methods to extract payment_details

            $paymentDetailsInput = null;

            // Method 1: Check if Laravel already parsed it (most common case)
            if ($request->has('payment_details')) {
                $parsed = $request->input('payment_details');
                if (is_array($parsed) && !empty($parsed)) {
                    $paymentDetailsInput = $parsed;
                }
            }

            // Method 2: Manually extract from all request keys (for form-data with nested arrays)
            // This handles cases where Laravel didn't auto-parse
            if (empty($paymentDetailsInput) || !is_array($paymentDetailsInput)) {
                $paymentDetailsInput = [];
                $allRequest = $request->all();
                foreach ($allRequest as $key => $value) {
                    if (!(is_string($key) && preg_match('/^payment_details\[(.+?)\]$/', $key, $matches))) {
                        continue;
                    }

                    if (isset($matches[1]) && $value !== null && $value !== '') {
                        $paymentDetailsInput[$matches[1]] = $value;
                    }
                }
            }

            // Method 3: Check raw JSON input (if request is JSON)
            if ((empty($paymentDetailsInput) || !is_array($paymentDetailsInput)) && $request->isJson()) {
                try {
                    $jsonInput = $request->json()->all();
                    if (isset($jsonInput['payment_details']) && is_array($jsonInput['payment_details'])) {
                        $paymentDetailsInput = $jsonInput['payment_details'];
                    } elseif (isset($jsonInput['payment_details']) && is_string($jsonInput['payment_details'])) {
                        $decoded = json_decode($jsonInput['payment_details'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $paymentDetailsInput = $decoded;
                        }
                    }
                } catch (\Exception) {
                    // If JSON parsing fails, continue with other methods
                }
            }

            // If payment_details is still empty after all extraction attempts, return error
            if (empty($paymentDetailsInput) || !is_array($paymentDetailsInput)) {
                return ApiResponseService::validationError(
                    'The payment details field is required. Please provide payment_details as an array or nested parameters like payment_details[account_holder_name].',
                );
            }

            // Prepare validation data - merge payment_details into request data
            $validationData = $request->all();
            $validationData['payment_details'] = $paymentDetailsInput;

            $validator = Validator::make($validationData, [
                'amount' => 'required|numeric|min:1|max:999999.99',
                'payment_method' => 'required|string|in:bank_transfer,paypal,stripe,razorpay',
                'payment_details' => 'required|array|min:1',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
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
            $paymentDetails = $this->validatePaymentDetails($request->payment_method, $paymentDetailsInput);
            if (!$paymentDetails['valid']) {
                return ApiResponseService::validationError($paymentDetails['message']);
            }

            DB::beginTransaction();

            // Determine entry type (user)
            $entryType = 'user';

            // Create withdrawal request
            $withdrawalRequest = WithdrawalRequest::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'entry_type' => $entryType,
                'payment_method' => $request->payment_method,
                'payment_details' => $paymentDetailsInput,
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
                'withdrawal_request' => [
                    'id' => $withdrawalRequest->id,
                    'amount' => (float) $withdrawalRequest->amount,
                    'status' => $withdrawalRequest->status,
                    'payment_method' => $withdrawalRequest->payment_method,
                    'created_at' => $withdrawalRequest->created_at->format('Y-m-d H:i:s'),
                ],
                'remaining_balance' => (float) $user->wallet_balance,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('Failed to create withdrawal request: ' . $e->getMessage());
        }
    }

    /**
     * Get user's withdrawal requests
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

            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
            }

            // Filter to show only user-side withdrawal requests
            $query = WithdrawalRequest::where('user_id', $user->id)->where(static function ($q): void {
                $q->where('entry_type', 'user')->orWhereNull('entry_type'); // Include old records without entry_type (treat as user)
            });

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $perPage = $request->per_page ?? 15;
            $withdrawalRequests = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Format the response
            $formattedRequests = $withdrawalRequests->map(static fn($request) => [
                'id' => $request->id,
                'amount' => (float) $request->amount,
                'status' => $request->status,
                'status_label' => ucfirst((string) $request->status),
                'entry_type' => $request->entry_type ?? 'user',
                'entry_type_label' => ucfirst($request->entry_type ?? 'user'),
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
     * Get withdrawal request details
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

            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
            }

            $withdrawalRequest = WithdrawalRequest::findOrFail($request->withdrawal_request_id);

            // Check if the withdrawal request belongs to the user
            if ($withdrawalRequest->user_id !== $user->id) {
                return ApiResponseService::errorResponse('Unauthorized access to withdrawal request.', [], 403);
            }

            // Check if it's a user-side withdrawal request
            if ($withdrawalRequest->entry_type !== 'user' && !is_null($withdrawalRequest->entry_type)) {
                return ApiResponseService::errorResponse(
                    'This withdrawal request is not a user-side request.',
                    [],
                    403,
                );
            }

            // Get wallet history related to this withdrawal
            $walletHistory = WalletHistory::where('user_id', $user->id)
                ->where('reference_type', \App\Models\WithdrawalRequest::class)
                ->where('reference_id', $withdrawalRequest->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(static fn($transaction) => [
                    'id' => $transaction->id,
                    'amount' => (float) $transaction->amount,
                    'type' => $transaction->type,
                    'transaction_type' => $transaction->transaction_type,
                    'description' => $transaction->description,
                    'balance_before' => (float) $transaction->balance_before,
                    'balance_after' => (float) $transaction->balance_after,
                    'created_at' => $transaction->created_at,
                    'created_at_formatted' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'time_ago' => $transaction->created_at->diffForHumans(),
                ]);

            $response = [
                'withdrawal_request' => [
                    'id' => $withdrawalRequest->id,
                    'amount' => (float) $withdrawalRequest->amount,
                    'status' => $withdrawalRequest->status,
                    'status_label' => ucfirst((string) $withdrawalRequest->status),
                    'entry_type' => $withdrawalRequest->entry_type ?? 'user',
                    'entry_type_label' => ucfirst($withdrawalRequest->entry_type ?? 'user'),
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
                'wallet_history' => $walletHistory,
            ];

            return ApiResponseService::successResponse('Withdrawal request details retrieved successfully', $response);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve withdrawal request details: '
            . $e->getMessage());
        }
    }

    /**
     * Validate payment details based on payment method
     */
    private function validatePaymentDetails($paymentMethod, $paymentDetails)
    {
        switch ($paymentMethod) {
            case 'bank_transfer':
                // Renamed IFSC to other_details as requested
                $required = ['account_holder_name', 'account_number', 'bank_name', 'other_details'];
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
            if (!isset($paymentDetails[$field]) || empty($paymentDetails[$field])) {
                return ['valid' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        return ['valid' => true];
    }
}
