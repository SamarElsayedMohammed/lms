<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WalletHistory;
use App\Models\WithdrawalRequest;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletApiController extends Controller
{
    public function __construct(private readonly PricingService $pricingService) {}

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

            // Detect user country & resolve display currency
            $countryCode     = $this->pricingService->detectUserCountry($request) ?: 'EG';
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            $availableForWithdrawal = (float) max(0, $walletBalance - $pendingWithdrawals);

            // Get recent transactions (last 5)
            $recentTransactions = WalletHistory::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(fn($transaction) => [
                    'id'                  => $transaction->id,
                    'amount'              => (float) $transaction->amount,
                    'local_amount'        => $this->pricingService->convertFromEgp((float) $transaction->amount, $displayCurrency),
                    'type'                => $transaction->type,
                    'transaction_type'    => $transaction->transaction_type,
                    'description'         => $transaction->description,
                    'balance_before'      => (float) $transaction->balance_before,
                    'balance_after'       => (float) $transaction->balance_after,
                    'local_balance_before' => $this->pricingService->convertFromEgp((float) $transaction->balance_before, $displayCurrency),
                    'local_balance_after'  => $this->pricingService->convertFromEgp((float) $transaction->balance_after, $displayCurrency),
                    'created_at'          => $transaction->created_at,
                    'created_at_formatted' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'time_ago'            => $transaction->created_at->diffForHumans(),
                ]);

            $summary = [
                'wallet_balance'               => (float) $walletBalance,
                'local_wallet_balance'         => $this->pricingService->convertFromEgp((float) $walletBalance, $displayCurrency),
                'currency'                     => $displayCurrency,
                'currency_symbol'              => $displaySymbol,
                'total_credits'                => (float) $totalCredits,
                'local_total_credits'          => $this->pricingService->convertFromEgp((float) $totalCredits, $displayCurrency),
                'total_debits'                 => (float) $totalDebits,
                'local_total_debits'           => $this->pricingService->convertFromEgp((float) $totalDebits, $displayCurrency),
                'total_withdrawals'            => (float) $totalWithdrawals,
                'local_total_withdrawals'      => $this->pricingService->convertFromEgp((float) $totalWithdrawals, $displayCurrency),
                'pending_withdrawals'          => (float) $pendingWithdrawals,
                'local_pending_withdrawals'    => $this->pricingService->convertFromEgp((float) $pendingWithdrawals, $displayCurrency),
                'available_for_withdrawal'     => $availableForWithdrawal,
                'local_available_for_withdrawal' => $this->pricingService->convertFromEgp($availableForWithdrawal, $displayCurrency),
                'recent_transactions'          => $recentTransactions,
            ];

            return ApiResponseService::successResponse('Wallet summary retrieved successfully', $summary);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
            }

            // Detect user country & resolve display currency
            $countryCode     = $this->pricingService->detectUserCountry($request) ?: 'EG';
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            $perPage = $request->per_page ?? 15;
            $currentPage = $request->page ?? 1;

            // 1. Fetch WalletHistory entries
            $historyQuery = WalletHistory::where('user_id', $user->id)
                ->with([
                    'reference' => static function ($morphTo): void {
                        $morphTo->morphWith([
                            \App\Models\Order::class => ['orderCourses.course'],
                            \App\Models\RefundRequest::class => ['course'],
                            \App\Models\WithdrawalRequest::class => [],
                            \App\Models\Commission::class => ['course', 'order'],
                            \App\Models\ManualDeposit::class => ['method'],
                        ]);
                    },
                ]);

            if ($request->filled('type')) {
                $historyQuery->where('type', $request->type);
            }

            $walletHistories = $historyQuery->orderBy('created_at', 'desc')->get();

            // 2. Fetch ALL Manual Deposits
            $manualDeposits = \App\Models\ManualDeposit::with('method')
                ->where('user_id', $user->id)
                ->get();

            // 3. Fetch ALL Withdrawal Requests
            $withdrawalRequests = \App\Models\WithdrawalRequest::where('user_id', $user->id)
                ->get();

            // 4. Build Unified List
            $unifiedList = collect();

            // Add all wallet histories
            foreach ($walletHistories as $history) {
                $unifiedList->push($this->formatHistoryItem($history, $displayCurrency));
            }

            // Add Manual Deposits that don't have history yet
            foreach ($manualDeposits as $deposit) {
                $exists = $walletHistories->contains(function ($h) use ($deposit) {
                    return $h->reference_type === \App\Models\ManualDeposit::class && $h->reference_id == $deposit->id;
                });

                if (!$exists) {
                    $amt = (float) $deposit->amount;
                    $unifiedList->push([
                        'id'              => null,
                        'amount'          => $amt,
                        'local_amount'    => $this->pricingService->convertFromEgp($amt, $displayCurrency),
                        'currency'        => $displayCurrency,
                        'currency_symbol' => $displaySymbol,
                        'type'            => 'credit',
                        'transaction_type' => 'deposit',
                        'description'     => 'Manual Deposit - ' . ($deposit->method ? $deposit->method->name : 'Request'),
                        'status'          => $deposit->status,
                        'created_at'      => $deposit->created_at->toDateTimeString(),
                        'created_at_formatted' => $deposit->created_at->format('Y-m-d H:i:s'),
                        'time_ago'        => $deposit->created_at->diffForHumans(),
                        'is_pending'      => $deposit->status === 'pending',
                        'payment_method'  => $deposit->method ? $deposit->method->name : null,
                        'transaction_id'  => $deposit->transaction_id,
                    ]);
                }
            }

            // Add Withdrawal Requests that don't have history yet
            foreach ($withdrawalRequests as $withdrawal) {
                $exists = $walletHistories->contains(function ($h) use ($withdrawal) {
                    return $h->reference_type === \App\Models\WithdrawalRequest::class && $h->reference_id == $withdrawal->id;
                });

                if (!$exists) {
                    $amt = (float) $withdrawal->amount;
                    $unifiedList->push([
                        'id'              => null,
                        'amount'          => $amt,
                        'local_amount'    => $this->pricingService->convertFromEgp($amt, $displayCurrency),
                        'currency'        => $displayCurrency,
                        'currency_symbol' => $displaySymbol,
                        'type'            => 'debit',
                        'transaction_type' => 'withdrawal',
                        'description'     => 'Withdrawal Request',
                        'status'          => $withdrawal->status,
                        'created_at'      => $withdrawal->created_at->toDateTimeString(),
                        'created_at_formatted' => $withdrawal->created_at->format('Y-m-d H:i:s'),
                        'time_ago'        => $withdrawal->created_at->diffForHumans(),
                        'is_pending'      => $withdrawal->status === 'pending',
                        'payment_method'  => $withdrawal->payment_method,
                        'payment_details' => $withdrawal->payment_details,
                    ]);
                }
            }

            // 5. Sort and Paginate
            $sortedList = $unifiedList->sortByDesc('created_at')->values();
            $total = $sortedList->count();
            $pagedData = $sortedList->forPage($currentPage, $perPage)->values();

            $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
                $pagedData,
                $total,
                $perPage,
                $currentPage,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            $responseData = $paginated->toArray();
            $responseData['currency']        = $displayCurrency;
            $responseData['currency_symbol'] = $displaySymbol;
            $responseData['is_withdrawal_request_pending'] = $withdrawalRequests->where('status', 'pending')->isNotEmpty();

            return ApiResponseService::successResponse('Wallet history retrieved successfully', $responseData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve wallet history: ' . $e->getMessage());
        }
    }

    /**
     * Helper to format WalletHistory item consistently
     */
    private function formatHistoryItem($history, string $displayCurrency = 'EGP')
    {
        $description = $history->description;
        $status = 'approved'; // If it's in WalletHistory, it's usually processed
        $paymentMethod = null;
        $transactionId = null;

        if ($history->reference) {
            $ref = $history->reference;
            if ($history->reference_type === \App\Models\ManualDeposit::class) {
                $status = $ref->status;
                $paymentMethod = $ref->method ? $ref->method->name : null;
                $transactionId = $ref->transaction_id;
            } elseif ($history->reference_type === \App\Models\WithdrawalRequest::class) {
                $status = $ref->status;
                $paymentMethod = $ref->payment_method;
            } elseif ($history->reference_type === \App\Models\Order::class) {
                $paymentMethod = $ref->payment_method;
                $status = $ref->status;
            }
        }

        // Topup fallback
        if ($history->transaction_type === 'wallet_topup' && !$transactionId) {
            $transactionId = $history->reference_id;
            $paymentMethod = 'Kashier';
        }

        $amt = (float) $history->amount;

        return [
            'id'                  => $history->id,
            'amount'              => $amt,
            'local_amount'        => $this->pricingService->convertFromEgp($amt, $displayCurrency),
            'type'                => $history->type,
            'transaction_type'    => $history->transaction_type,
            'description'         => $description,
            'balance_before'      => (float) $history->balance_before,
            'balance_after'       => (float) $history->balance_after,
            'local_balance_before' => $this->pricingService->convertFromEgp((float) $history->balance_before, $displayCurrency),
            'local_balance_after'  => $this->pricingService->convertFromEgp((float) $history->balance_after, $displayCurrency),
            'status'              => $status,
            'created_at'          => $history->created_at->toDateTimeString(),
            'created_at_formatted' => $history->created_at->format('Y-m-d H:i:s'),
            'time_ago'            => $history->created_at->diffForHumans(),
            'is_pending'          => false,
            'payment_method'      => $paymentMethod,
            'transaction_id'      => $transactionId,
        ];
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
                'payment_method' => 'required|string|exists:withdrawal_methods,code',
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

            $withdrawalMethod = \App\Models\WithdrawalMethod::where('code', $request->payment_method)->where('is_active', true)->first();
            if (!$withdrawalMethod) {
                return ApiResponseService::validationError('طريقة السحب المحددة غير متاحة حالياً.');
            }

            if ($amount < $withdrawalMethod->min_amount) {
                return ApiResponseService::validationError("عذراً، الحد الأدنى للسحب هو {$withdrawalMethod->min_amount} {$withdrawalMethod->currency}");
            }
            if ($amount > $withdrawalMethod->max_amount) {
                return ApiResponseService::validationError("عذراً، الحد الأقصى للسحب هو {$withdrawalMethod->max_amount} {$withdrawalMethod->currency}");
            }

            // Validate payment details based on method
            $paymentDetails = $this->validatePaymentDetails($withdrawalMethod, $paymentDetailsInput);
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

            // Withdrawal request is created as 'pending'.
            // Balance will be deducted ONLY when admin approves the request.
            // We already checked if user has sufficient balance in line 547.

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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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

            // Detect user country & resolve display currency
            $countryCode     = $this->pricingService->detectUserCountry($request) ?: 'EG';
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            $perPage = $request->per_page ?? 15;
            $withdrawalRequests = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Format the response
            $formattedRequests = $withdrawalRequests->map(fn($wr) => [
                'id'              => $wr->id,
                'amount'          => (float) $wr->amount,
                'local_amount'    => $this->pricingService->convertFromEgp((float) $wr->amount, $displayCurrency),
                'currency'        => $displayCurrency,
                'currency_symbol' => $displaySymbol,
                'status'          => $wr->status,
                'status_label'    => ucfirst((string) $wr->status),
                'entry_type'      => $wr->entry_type ?? 'user',
                'entry_type_label' => ucfirst($wr->entry_type ?? 'user'),
                'payment_method'  => $wr->payment_method,
                'payment_method_label' => ucwords(str_replace('_', ' ', $wr->payment_method)),
                'payment_details' => $wr->payment_details,
                'notes'           => $wr->notes,
                'admin_notes'     => $wr->admin_notes,
                'created_at'      => $wr->created_at,
                'created_at_formatted' => $wr->created_at->format('Y-m-d H:i:s'),
                'time_ago'        => $wr->created_at->diffForHumans(),
                'processed_at'    => $wr->processed_at,
                'processed_at_formatted' => $wr->processed_at
                    ? $wr->processed_at->format('Y-m-d H:i:s')
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve withdrawal requests: ' . $e->getMessage());
        }
    }

    /**
     * Get available withdrawal methods
     */
    public function getWithdrawalMethods(Request $request)
    {
        $methods = \App\Models\WithdrawalMethod::where('is_active', true)->get()->map(function ($method) {
            return [
                'id' => (string) $method->id,
                'name' => $method->name,
                'code' => $method->code,
                'currency' => $method->currency,
                'min_amount' => $method->min_amount,
                'max_amount' => $method->max_amount,
                'fixed_fee' => $method->fixed_fee,
                'percent_fee' => $method->percent_fee,
                'estimated_delay' => $method->estimated_delay,
                'description' => $method->description,
                'fields' => $method->dynamic_fields ?? [],
                'is_active' => $method->is_active,
                'image' => $method->image,
            ];
        });

        return ApiResponseService::successResponse('Withdrawal methods retrieved successfully', $methods);
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

            // Detect user country & resolve display currency
            $countryCode     = $this->pricingService->detectUserCountry($request) ?: 'EG';
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            // Get wallet history related to this withdrawal
            $walletHistory = WalletHistory::where('user_id', $user->id)
                ->where('reference_type', \App\Models\WithdrawalRequest::class)
                ->where('reference_id', $withdrawalRequest->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn($transaction) => [
                    'id'                  => $transaction->id,
                    'amount'              => (float) $transaction->amount,
                    'local_amount'        => $this->pricingService->convertFromEgp((float) $transaction->amount, $displayCurrency),
                    'type'                => $transaction->type,
                    'transaction_type'    => $transaction->transaction_type,
                    'description'         => $transaction->description,
                    'balance_before'      => (float) $transaction->balance_before,
                    'balance_after'       => (float) $transaction->balance_after,
                    'local_balance_before' => $this->pricingService->convertFromEgp((float) $transaction->balance_before, $displayCurrency),
                    'local_balance_after'  => $this->pricingService->convertFromEgp((float) $transaction->balance_after, $displayCurrency),
                    'created_at'          => $transaction->created_at,
                    'created_at_formatted' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'time_ago'            => $transaction->created_at->diffForHumans(),
                ]);

            $response = [
                'currency'        => $displayCurrency,
                'currency_symbol' => $displaySymbol,
                'withdrawal_request' => [
                    'id'           => $withdrawalRequest->id,
                    'amount'       => (float) $withdrawalRequest->amount,
                    'local_amount' => $this->pricingService->convertFromEgp((float) $withdrawalRequest->amount, $displayCurrency),
                    'currency'     => $displayCurrency,
                    'currency_symbol' => $displaySymbol,
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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve withdrawal request details: '
            . $e->getMessage());
        }
    }

    /**
     * Validate payment details based on method dynamic fields
     */
    private function validatePaymentDetails($withdrawalMethod, $details)
    {
        if (empty($withdrawalMethod->dynamic_fields)) {
            return ['valid' => true, 'message' => ''];
        }

        foreach ($withdrawalMethod->dynamic_fields as $field) {
            $fieldName = $field['name'];
            $isRequired = $field['required'] ?? false;
            $label = $field['label'] ?? $fieldName;

            if ($isRequired && (!isset($details[$fieldName]) || trim((string)$details[$fieldName]) === '')) {
                return [
                    'valid' => false,
                    'message' => "The field '{$label}' is required for this withdrawal method.",
                ];
            }
        }

        return ['valid' => true, 'message' => ''];
    }
}
