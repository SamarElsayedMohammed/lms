<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WalletHistory;
use App\Models\WithdrawalRequest;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use App\Services\PricingService;
use App\Services\WalletService;
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

            // Get wallet balance (EGP ledger)
            $walletBalance = (float) ($user->wallet_balance ?? 0);

            // Sum EGP ledger columns — `amount` is local currency and must not be FX'd again.
            $creditEgp = (float) WalletHistory::where('user_id', $user->id)
                ->where('type', 'credit')
                ->selectRaw('SUM(COALESCE(amount_egp, amount)) as total')
                ->value('total');
            $debitEgp = (float) WalletHistory::where('user_id', $user->id)
                ->where('type', 'debit')
                ->selectRaw('SUM(COALESCE(amount_egp, amount)) as total')
                ->value('total');

            $pendingWithdrawals = WithdrawalRequest::where('user_id', $user->id)->whereIn('status', [
                'pending',
                'processing',
            ])->selectRaw('SUM(COALESCE(amount_egp, amount)) as total')->value('total');

            $totalWithdrawals = WithdrawalRequest::where('user_id', $user->id)->whereIn('status', [
                'approved',
                'completed',
            ])->selectRaw('SUM(COALESCE(amount_egp, amount)) as total')->value('total');

            $countryCode     = $this->pricingService->detectUserCountry($request);
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            $availableForWithdrawal = $walletBalance;
            $localBalance = $this->pricingService->convertFromEgp($walletBalance, $displayCurrency);
            $localCredits = $this->pricingService->convertFromEgp($creditEgp, $displayCurrency);
            $localDebits = $this->pricingService->convertFromEgp($debitEgp, $displayCurrency);
            $localWithdrawals = $this->pricingService->convertFromEgp((float) $totalWithdrawals, $displayCurrency);
            $localPending = $this->pricingService->convertFromEgp((float) $pendingWithdrawals, $displayCurrency);

            $recentTransactions = WalletHistory::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(fn($transaction) => $this->formatHistoryItem($transaction, $displayCurrency, $displaySymbol));

            $summary = [
                'wallet_balance'               => $walletBalance,
                'balance'                      => $localBalance,
                'local_wallet_balance'         => $localBalance,
                'currency'                     => $displayCurrency,
                'currency_code'                => $displayCurrency,
                'currency_symbol'              => $displaySymbol,
                'total_credits'                => $localCredits,
                'local_total_credits'          => $localCredits,
                'total_debits'                 => $localDebits,
                'local_total_debits'           => $localDebits,
                'total_withdrawals'            => $localWithdrawals,
                'local_total_withdrawals'      => $localWithdrawals,
                'pending_withdrawals'          => $localPending,
                'local_pending_withdrawals'    => $localPending,
                'available_for_withdrawal'     => $this->pricingService->convertFromEgp($availableForWithdrawal, $displayCurrency),
                'local_available_for_withdrawal' => $this->pricingService->convertFromEgp($availableForWithdrawal, $displayCurrency),
                'is_withdrawal_request_pending' => $pendingWithdrawals > 0,
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

            \App\Models\WalletTopUpAttempt::create([
                'user_id' => $user->id,
                'order_id' => $result['order_id'],
                'amount_egp' => $amount,
                'status' => 'pending',
                'expires_at' => now()->addHours(4),
            ]);

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
            $validator = Validator::make($request->all(), [
                'type' => 'nullable|in:credit,debit',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'payment' => 'nullable|string|max:100',
            ]);
            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('Authentication required.');
            }

            // Detect user country & resolve display currency
            $countryCode     = $this->pricingService->detectUserCountry($request);
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            $perPage = (int) $request->input('per_page', 15);
            $currentPage = (int) $request->input('page', 1);

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

            // Build an O(1) lookup set of recorded reference keys: "ModelClass:Id"
            $recordedRefs = [];
            foreach ($walletHistories as $wh) {
                if ($wh->reference_type && $wh->reference_id) {
                    $recordedRefs[$wh->reference_type . ':' . $wh->reference_id] = true;
                }
            }

            // 2. Fetch pending/unrecorded Manual Deposits
            $manualDeposits = \App\Models\ManualDeposit::with('method')
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->get();

            // 3. Fetch ALL Withdrawal Requests for pending status determination
            $withdrawalRequests = \App\Models\WithdrawalRequest::where('user_id', $user->id)
                ->get();

            // 4. Build Unified List
            $unifiedList = collect();

            // Add all wallet histories
            foreach ($walletHistories as $history) {
                    $unifiedList->push($this->formatHistoryItem($history, $displayCurrency, $displaySymbol));
            }

            // Add pending Manual Deposits that don't have a ledger entry yet
            foreach ($manualDeposits as $deposit) {
                $refKey = \App\Models\ManualDeposit::class . ':' . $deposit->id;
                if (!isset($recordedRefs[$refKey])) {
                    $amt = (float) $deposit->amount;
                    $localAmt = $this->pricingService->convertFromEgp($amt, $displayCurrency);
                    $unifiedList->push([
                        'id'              => 'deposit-' . $deposit->id,
                        'amount'          => $localAmt,
                        'amount_egp'      => $amt,
                        'local_amount'    => $localAmt,
                        'currency'        => $displayCurrency,
                        'currency_symbol' => $displaySymbol,
                        'type'            => 'credit',
                        'transaction_type' => 'deposit',
                        'description'     => 'Manual Deposit - ' . ($deposit->method ? $deposit->method->name : 'Request'),
                        'status'          => $deposit->status,
                        'created_at'      => $deposit->created_at->toIso8601String(),
                        'created_at_formatted' => $deposit->created_at->toIso8601String(),
                        'time_ago'        => $deposit->created_at->diffForHumans(),
                        'is_pending'      => $deposit->status === 'pending',
                        'payment_method'  => $deposit->method ? $deposit->method->name : null,
                        'transaction_id'  => $deposit->transaction_id,
                    ]);
                }
            }

            // Add Withdrawal Requests that don't have a ledger entry yet (if any edge case exists)
            foreach ($withdrawalRequests as $withdrawal) {
                $refKey = \App\Models\WithdrawalRequest::class . ':' . $withdrawal->id;
                if (!isset($recordedRefs[$refKey])) {
                    $amt = (float) ($withdrawal->amount_egp ?? $withdrawal->amount);
                    $localAmt = $this->pricingService->convertFromEgp($amt, $displayCurrency);
                    $unifiedList->push([
                        'id'              => 'withdrawal-' . $withdrawal->id,
                        'amount'          => $localAmt,
                        'amount_egp'      => $amt,
                        'local_amount'    => $localAmt,
                        'currency'        => $displayCurrency,
                        'currency_symbol' => $displaySymbol,
                        'type'            => 'debit',
                        'transaction_type' => 'withdrawal',
                        'description'     => 'Withdrawal Request',
                        'status'          => $withdrawal->status,
                        'created_at'      => $withdrawal->created_at->toIso8601String(),
                        'created_at_formatted' => $withdrawal->created_at->toIso8601String(),
                        'time_ago'        => $withdrawal->created_at->diffForHumans(),
                        'is_pending'      => in_array($withdrawal->status, ['pending', 'processing'], true),
                        'payment_method'  => $withdrawal->payment_method,
                    ]);
                }
            }

            // 5. Sort and Paginate
            $sortedList = $unifiedList
                ->when($request->filled('type'), fn ($items) => $items->where('type', $request->input('type')))
                ->sortByDesc('created_at')
                ->values();
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
            $responseData['is_withdrawal_request_pending'] = $withdrawalRequests->whereIn('status', ['pending', 'processing'])->isNotEmpty();

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
    private function formatHistoryItem($history, string $displayCurrency = 'EGP', string $displaySymbol = 'ج.م')
    {
        $description = $history->description;
        $status = 'approved';
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

        if ($history->transaction_type === 'wallet_topup' && !$transactionId) {
            $transactionId = $history->reference_id;
            $paymentMethod = 'Kashier';
        }

        $amountEgp = $history->amount_egp !== null
            ? (float) $history->amount_egp
            : (float) $history->amount;
        $localAmount = $this->pricingService->convertFromEgp($amountEgp, $displayCurrency);

        return [
            'id'                  => $history->id,
            'amount'              => $localAmount,
            'amount_egp'          => $amountEgp,
            'local_amount'        => $localAmount,
            'currency'            => $displayCurrency,
            'currency_symbol'     => $displaySymbol,
            'type'                => $history->type,
            'transaction_type'    => $history->transaction_type,
            'description'         => $description,
            'balance_before'      => (float) $history->balance_before,
            'balance_after'       => (float) $history->balance_after,
            'local_balance_before' => $this->pricingService->convertFromEgp((float) $history->balance_before, $displayCurrency),
            'local_balance_after'  => $this->pricingService->convertFromEgp((float) $history->balance_after, $displayCurrency),
            'status'              => $status,
            'created_at'          => $history->created_at->toIso8601String(),
            'created_at_formatted' => $history->created_at->toIso8601String(),
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
                'currency_code' => 'nullable|string|size:3',
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

            $withdrawalMethod = \App\Models\WithdrawalMethod::where('code', $request->payment_method)
                ->where('is_active', true)
                ->first();
            if ($withdrawalMethod === null) {
                return ApiResponseService::validationError('The selected withdrawal method is not available.');
            }

            $amount = (float) $request->amount;
            $sourceCurrency = strtoupper((string) ($withdrawalMethod->currency ?: 'EGP'));
            if ($request->filled('currency_code') && strtoupper((string) $request->currency_code) !== $sourceCurrency) {
                return ApiResponseService::validationError('The withdrawal currency does not match the selected method.');
            }

            if ($withdrawalMethod->min_amount !== null && $amount < (float) $withdrawalMethod->min_amount) {
                return ApiResponseService::validationError('The withdrawal amount is below the selected method minimum.');
            }
            if ($withdrawalMethod->max_amount !== null && $amount > (float) $withdrawalMethod->max_amount) {
                return ApiResponseService::validationError('The withdrawal amount exceeds the selected method maximum.');
            }

            $currencyConversionService = app(\App\Services\CurrencyConversionService::class);
            if ($sourceCurrency !== 'EGP' && $currencyConversionService->getCurrency($sourceCurrency) === null) {
                return ApiResponseService::validationError('The selected withdrawal currency is not supported.');
            }
            $exchangeRate = $currencyConversionService->getExchangeRateToEgp($sourceCurrency);
            $amountEgp = $currencyConversionService->convertToEgp($amount, $sourceCurrency);
            $fixedFee = max(0, (float) ($withdrawalMethod->fixed_fee ?? 0));
            $percentFee = max(0, (float) ($withdrawalMethod->percent_fee ?? 0));
            $feeAmount = round($fixedFee + ($amount * $percentFee / 100), 2);
            $netAmount = round($amount - $feeAmount, 2);
            if ($netAmount <= 0) {
                return ApiResponseService::validationError('The net withdrawal amount after fees must be greater than zero.');
            }
            $feeAmountEgp = $currencyConversionService->convertToEgp($feeAmount, $sourceCurrency);
            $netAmountEgp = $currencyConversionService->convertToEgp($netAmount, $sourceCurrency);

            // Validate payment details based on method
            $paymentDetails = $this->validatePaymentDetails($withdrawalMethod, $paymentDetailsInput);
            if (!$paymentDetails['valid']) {
                return ApiResponseService::validationError($paymentDetails['message']);
            }

            DB::beginTransaction();

            // Lock user row to prevent race conditions during balance check
            $lockedUser = \App\Models\User::lockForUpdate()->find($user->id);
            if (!$lockedUser) {
                DB::rollBack();
                return ApiResponseService::errorResponse('User record not found.', [], 404);
            }

            // Check if user has sufficient wallet balance under lock
            if ((float) $lockedUser->wallet_balance < $amountEgp) {
                DB::rollBack();
                $currencySymbol = HelperService::systemSettings('currency_symbol') ?? '$';
                return ApiResponseService::validationError('Insufficient wallet balance. Available: '
                . $currencySymbol
                . number_format($lockedUser->wallet_balance, 2));
            }

            // Check if user has any pending withdrawal requests under lock
            $pendingRequest = WithdrawalRequest::where('user_id', $lockedUser->id)
                ->whereIn('status', ['pending', 'processing'])
                ->lockForUpdate()
                ->first();

            if ($pendingRequest) {
                DB::rollBack();
                return ApiResponseService::validationError(
                    'You already have a pending withdrawal request. Please wait for it to be processed.',
                );
            }

            // Determine entry type (user)
            $entryType = 'user';
            
            // Create withdrawal request
            $withdrawalRequest = WithdrawalRequest::create([
                'user_id' => $lockedUser->id,
                'amount' => $amount,
                'fee_amount' => $feeAmount,
                'net_amount' => $netAmount,
                'amount_egp' => $amountEgp,
                'fee_amount_egp' => $feeAmountEgp,
                'net_amount_egp' => $netAmountEgp,
                'exchange_rate_snapshot' => $exchangeRate,
                'currency_code' => $sourceCurrency,
                'entry_type' => $entryType,
                'payment_method' => $request->payment_method,
                'method_snapshot' => [
                    'id' => $withdrawalMethod->id,
                    'code' => $withdrawalMethod->code,
                    'name' => $withdrawalMethod->name,
                    'currency' => $sourceCurrency,
                    'fixed_fee' => $fixedFee,
                    'percent_fee' => $percentFee,
                ],
                'payment_details' => $paymentDetailsInput,
                'notes' => $request->notes,
                'status' => 'pending',
            ]);

            // Deduct the requested amount from user's wallet immediately to prevent double spending
            WalletService::debitWallet(
                $lockedUser->id,
                $amountEgp,
                'withdrawal',
                "Withdrawal request #{$withdrawalRequest->id} submitted",
                $withdrawalRequest->id,
                \App\Models\WithdrawalRequest::class,
                $entryType
            );

            DB::commit();

            $freshUser = $lockedUser->fresh();

            return ApiResponseService::successResponse('Withdrawal request created successfully', [
                'withdrawal_request' => [
                    'id' => $withdrawalRequest->id,
                    'amount' => (float) $withdrawalRequest->amount,
                    'fee_amount' => (float) $withdrawalRequest->fee_amount,
                    'net_amount' => (float) $withdrawalRequest->net_amount,
                    'status' => $withdrawalRequest->status,
                    'payment_method' => $withdrawalRequest->payment_method,
                    'created_at' => $withdrawalRequest->created_at->format('Y-m-d H:i:s'),
                ],
                'remaining_balance' => (float) ($freshUser ? $freshUser->wallet_balance : 0),
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
            $countryCode     = $this->pricingService->detectUserCountry($request);
            $currencyObj     = $this->pricingService->getCurrencyForCountry($countryCode);
            $displayCurrency = $currencyObj ? $currencyObj->currency_code  : 'EGP';
            $displaySymbol   = $currencyObj ? $currencyObj->currency_symbol : 'ج.م';

            $perPage = $request->per_page ?? 15;
            $withdrawalRequests = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Format the response
            $formattedRequests = $withdrawalRequests->map(fn($wr) => [
                'id'              => $wr->id,
                'amount'          => (float) ($wr->amount_egp ?? $wr->amount),
                'fee_amount'      => (float) ($wr->fee_amount_egp ?? $wr->fee_amount ?? 0),
                'net_amount'      => (float) ($wr->net_amount_egp ?? $wr->net_amount ?? $wr->amount_egp ?? $wr->amount),
                'local_amount'    => $this->pricingService->convertFromEgp((float) ($wr->amount_egp ?? $wr->amount), $displayCurrency),
                'local_fee_amount' => $this->pricingService->convertFromEgp((float) ($wr->fee_amount_egp ?? 0), $displayCurrency),
                'local_net_amount' => $this->pricingService->convertFromEgp((float) ($wr->net_amount_egp ?? $wr->amount_egp ?? $wr->amount), $displayCurrency),
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
            $countryCode     = $this->pricingService->detectUserCountry($request);
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
                    'amount'              => (float) ($transaction->amount_egp ?? $transaction->amount),
                    'local_amount'        => (float) $transaction->amount,
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
                    'amount'       => (float) ($withdrawalRequest->amount_egp ?? $withdrawalRequest->amount),
                    'fee_amount'   => (float) ($withdrawalRequest->fee_amount_egp ?? $withdrawalRequest->fee_amount ?? 0),
                    'net_amount'   => (float) ($withdrawalRequest->net_amount_egp ?? $withdrawalRequest->net_amount ?? $withdrawalRequest->amount_egp ?? $withdrawalRequest->amount),
                    'local_amount' => $this->pricingService->convertFromEgp((float) ($withdrawalRequest->amount_egp ?? $withdrawalRequest->amount), $displayCurrency),
                    'local_fee_amount' => $this->pricingService->convertFromEgp((float) ($withdrawalRequest->fee_amount_egp ?? 0), $displayCurrency),
                    'local_net_amount' => $this->pricingService->convertFromEgp((float) ($withdrawalRequest->net_amount_egp ?? $withdrawalRequest->amount_egp ?? $withdrawalRequest->amount), $displayCurrency),
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
