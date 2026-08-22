<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class WebinarApiController extends Controller
{
    /**
     * List all scheduled/live webinars
     */
    public function index(Request $request)
    {
        try {
            $query = Webinar::with(['instructor:id,name,profile', 'course:id,title'])
                ->where('is_published', true)
                ->whereIn('status', ['scheduled', 'live'])
                ->where('start_at', '>=', now()->subHours(2));

            if ($request->has('course_id')) {
                $query->where('course_id', $request->input('course_id'));
            }

            $perPage = min((int) $request->input('per_page', 15), 50);
            $webinars = $query->orderBy('start_at', 'asc')->paginate($perPage);

            return ApiResponseService::successResponse('Webinars retrieved successfully', $webinars);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve webinars: ' . $e->getMessage());
        }
    }

    /**
     * Get webinar details
     */
    public function show($param)
    {
        try {
            $webinar = Webinar::with(['instructor:id,name,profile', 'registrations'])
                ->where('id', $param)
                ->orWhere('slug', $param)
                ->first();

            if (!$webinar) {
                return ApiResponseService::errorResponse('Webinar not found', [], 404);
            }

            $user = Auth::guard('sanctum')->user();
            $is_registered = $user ? WebinarRegistration::where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)
                ->whereIn('payment_status', ['paid', 'free'])
                ->exists() : false;

            // Hide sensitive links if not registered or not completed
            if (!$is_registered) {
                $webinar->makeHidden(['join_url', 'meeting_password']);
            }
            if (!$is_registered || $webinar->status !== 'completed') {
                $webinar->makeHidden(['recording_url']);
            }

            // Append computed attributes
            $webinar->append(['spots_left', 'is_full']);

            return ApiResponseService::successResponse('Webinar details retrieved', [
                'webinar' => $webinar,
                'is_registered' => $is_registered,
                'registered_count' => $webinar->registrations()->count(),
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve webinar: ' . $e->getMessage());
        }
    }

    /**
     * Register for a webinar
     */
    public function register(Request $request, $param)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthorized.', [], 401);
            }

            $request->validate([
                'use_wallet' => 'nullable|boolean',
                'form_responses' => 'nullable|array|max:50',
                'utm_source' => 'nullable|string|max:255',
                'name' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'mobile' => 'nullable|string|max:50',
                'whatsapp' => 'nullable|string|max:50',
            ]);

            $formResponses = $request->input('form_responses');
            if (!is_array($formResponses)) {
                $formResponses = collect($request->except(['use_wallet', 'utm_source', 'form_responses']))
                    ->filter(static fn ($value) => is_scalar($value) || $value === null)
                    ->take(50)
                    ->all();
            }
            $utmSource = $request->filled('utm_source') ? trim((string) $request->utm_source) : null;

            $webinar = Webinar::where('id', $param)->orWhere('slug', $param)->firstOrFail();

            if ($webinar->status === 'completed' || $webinar->status === 'cancelled') {
                return ApiResponseService::errorResponse('This webinar is no longer available for registration.');
            }

            if ($webinar->is_full) {
                return ApiResponseService::errorResponse('This webinar is full. No more registrations allowed.');
            }

            $existing = WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->first();
            if ($existing) {
                if ($existing->isConfirmed()) {
                    return ApiResponseService::successResponse('You are already registered for this webinar.');
                }
                if (!$existing->isExpired()) {
                    return ApiResponseService::validationError('A webinar payment is already pending.');
                }
                $existing->delete();
            }

            if (!$webinar->is_free && $webinar->price > 0) {
                // Calculate localized pricing
                $pricingService = app(\App\Services\PricingCalculationService::class);
                $currencyInfo = $pricingService->resolveDisplayCurrency($user, $request);
                $exchangeRate = max(0.0001, (float) $currencyInfo['exchange_rate']);
                $currency = $currencyInfo['code'];
                $priceEgp = (float) $webinar->price;
                $totalAmount = app(\App\Services\CurrencyConversionService::class)
                    ->convertFromEgp($priceEgp, $currency);
                $useWallet = $request->boolean('use_wallet');
                $walletAmountEgp = $useWallet && (float) $user->wallet_balance >= $priceEgp ? $priceEgp : 0.0;
                // Partial wallet holds are not created for webinars. If the
                // wallet cannot cover the full EGP price, charge the full local
                // amount through the gateway to avoid an unfunded split later.
                $gatewayAmount = $walletAmountEgp > 0 ? 0.0 : $totalAmount;

                // If fully paid by wallet
                if ($gatewayAmount <= 0) {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($user, $webinar, $walletAmountEgp, $totalAmount, $currency, $exchangeRate, $priceEgp, $formResponses, $utmSource) {
                        $lockedWebinar = Webinar::whereKey($webinar->id)->lockForUpdate()->firstOrFail();
                        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                        if ($lockedWebinar->is_full) {
                            return ApiResponseService::validationError('This webinar is full. No more registrations allowed.');
                        }
                        if (WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->exists()) {
                            return ApiResponseService::validationError('A webinar registration already exists.');
                        }

                        $walletTransaction = \App\Services\WalletService::debitWallet(
                            $user->id,
                            $walletAmountEgp,
                            'webinar_payment',
                            'Paid for webinar: ' . $webinar->title,
                            (string) $webinar->id,
                            'webinar'
                        );

                        WebinarRegistration::create([
                            'user_id' => $user->id,
                            'webinar_id' => $webinar->id,
                            'payment_status' => 'paid',
                            'paid_amount' => $totalAmount,
                            'amount_egp' => $priceEgp,
                            'currency_code' => $currency,
                            'exchange_rate_snapshot' => $exchangeRate,
                            'wallet_amount_egp' => $walletAmountEgp,
                            'gateway_amount' => 0,
                            'wallet_transaction_id' => $walletTransaction->id,
                            'form_responses' => $formResponses,
                            'utm_source' => $utmSource,
                        ]);
                    });

                    $user->notify(new \App\Notifications\WebinarRegistrationNotification($webinar));

                    return ApiResponseService::successResponse('Successfully registered for the webinar using wallet.');
                }

                // If requires Kashier gateway
                try {
                    $kashierService = app(\App\Services\Payment\KashierCheckoutService::class);
                    $checkout = $kashierService->createWebinarCheckoutSession($webinar->id, $user, $gatewayAmount, $currency);
                } catch (\RuntimeException $e) {
                    return ApiResponseService::errorResponse('Payment gateway is not configured.', [], 503);
                }

                // Create a pending registration
                \Illuminate\Support\Facades\DB::transaction(function () use ($user, $webinar, $totalAmount, $priceEgp, $currency, $exchangeRate, $gatewayAmount, $checkout, $formResponses, $utmSource) {
                    $lockedWebinar = Webinar::whereKey($webinar->id)->lockForUpdate()->firstOrFail();
                    \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                    if ($lockedWebinar->is_full) {
                        return ApiResponseService::validationError('This webinar is full. No more registrations allowed.');
                    }
                    if (WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->exists()) {
                        return ApiResponseService::validationError('A webinar registration already exists.');
                    }

                    WebinarRegistration::create([
                        'user_id' => $user->id,
                        'webinar_id' => $webinar->id,
                        'payment_status' => 'pending',
                        'paid_amount' => 0,
                        'amount_egp' => $priceEgp,
                        'currency_code' => $currency,
                        'exchange_rate_snapshot' => $exchangeRate,
                        'wallet_amount_egp' => 0,
                        'gateway_amount' => $gatewayAmount,
                        'gateway_order_id' => $checkout['order_id'],
                        'expires_at' => now()->addHour(),
                        'form_responses' => $formResponses,
                        'utm_source' => $utmSource,
                    ]);
                });

                \Illuminate\Support\Facades\Cache::put('kashier_pending_' . $checkout['order_id'], [
                    'wallet_amount_egp' => 0,
                    'expected_amount' => $gatewayAmount,
                    'expected_currency' => $currency,
                    'webinar_id' => $webinar->id,
                    'user_id' => $user->id,
                ], 3600); // Cache is an optimization; DB snapshots are authoritative.

                return ApiResponseService::successResponse('Please complete payment via Kashier.', [
                    'requires_checkout' => true,
                    'checkout_url' => $checkout['url'],
                    'order_id' => $checkout['order_id'],
                    'payment' => [
                        'total_amount' => $totalAmount,
                        'wallet_amount' => 0,
                        'wallet_amount_egp' => 0,
                        'gateway_amount' => $gatewayAmount,
                        'currency' => $currency,
                    ],
                ]);
            }

            // Free webinar
            \Illuminate\Support\Facades\DB::transaction(function () use ($user, $webinar, $formResponses, $utmSource) {
                $lockedWebinar = Webinar::whereKey($webinar->id)->lockForUpdate()->firstOrFail();
                \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                if ($lockedWebinar->is_full) {
                    return ApiResponseService::validationError('This webinar is full. No more registrations allowed.');
                }
                if (WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->exists()) {
                    return ApiResponseService::validationError('A webinar registration already exists.');
                }
                WebinarRegistration::create([
                    'user_id' => $user->id,
                    'webinar_id' => $webinar->id,
                    'payment_status' => 'free',
                    'paid_amount' => 0,
                    'form_responses' => $formResponses,
                    'utm_source' => $utmSource,
                ]);
            });

            $user->notify(new \App\Notifications\WebinarRegistrationNotification($webinar));

            return ApiResponseService::successResponse('Successfully registered for the webinar.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to register: ' . $e->getMessage());
        }
    }

    /**
     * Join a webinar (get join URL)
     */
    public function join($param)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthorized.', [], 401);
            }
            $webinar = Webinar::where('id', $param)->orWhere('slug', $param)->firstOrFail();

            $registration = WebinarRegistration::where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)
                ->first();

            if (!$registration) {
                return ApiResponseService::errorResponse('You must register for this webinar first.');
            }

            if (!$webinar->is_free && !in_array($registration->payment_status, ['paid', 'free'])) {
                return ApiResponseService::errorResponse('You must complete payment for this webinar first.');
            }

            if ($webinar->status === 'scheduled' && $webinar->start_at->gt(now()->addMinutes(15))) {
                return ApiResponseService::errorResponse('The webinar has not started yet. You can join 15 minutes before the start time.');
            }

            // Logic to generate provider URL if needed (e.g. for Jitsi)
            $join_url = $webinar->join_url;
            if ($webinar->provider === 'jitsi' && empty($join_url)) {
                $join_url = "https://meet.jit.si/" . $webinar->slug;
            }

            return ApiResponseService::successResponse('Join link generated', [
                'join_url' => $join_url,
                'meeting_id' => $webinar->meeting_id,
                'meeting_password' => $webinar->meeting_password
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to join webinar: ' . $e->getMessage());
        }
    }

    /**
     * Cancel registration for a webinar
     */
    public function cancelRegistration(Request $request, $param)
    {
        try {
            $user = Auth::guard('sanctum')->user();
            if (!$user) {
                return ApiResponseService::errorResponse('Unauthorized.', [], 401);
            }

            $webinar = Webinar::where('id', $param)->orWhere('slug', $param)->firstOrFail();

            $registration = WebinarRegistration::where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)
                ->first();

            if (!$registration) {
                return ApiResponseService::errorResponse('Not registered for this webinar.', [], 400);
            }

            if ($registration->payment_status === 'pending') {
                return ApiResponseService::validationError('A pending gateway payment cannot be cancelled from the registration endpoint.');
            }

            if (!$webinar->is_free && $registration->payment_status === 'paid'
                && $webinar->start_at && $webinar->start_at->isPast()) {
                return ApiResponseService::errorResponse('Cannot cancel registration after the webinar has started.', [], 400);
            }

            $gatewayOrderId = $registration->gateway_order_id;
            \Illuminate\Support\Facades\DB::transaction(function () use ($registration, $user, $webinar): void {
                $lockedRegistration = WebinarRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();

                if (!$webinar->is_free && $lockedRegistration->payment_status === 'paid') {
                    $amountToRefundEgp = (float) ($lockedRegistration->amount_egp ?? $webinar->price);
                    if ($amountToRefundEgp <= 0) {
                        throw new \RuntimeException('Invalid webinar refund amount.');
                    }

                    \App\Services\WalletService::creditWallet(
                        $user->id,
                        $amountToRefundEgp,
                        'webinar_refund',
                        'Webinar registration refund: ' . $webinar->title,
                        $lockedRegistration->id,
                        WebinarRegistration::class,
                        'user',
                    );
                }

                $lockedRegistration->delete();
            });

            if ($gatewayOrderId) {
                \Illuminate\Support\Facades\Cache::forget('kashier_pending_' . $gatewayOrderId);
            }

            return ApiResponseService::successResponse('Registration cancelled successfully.');

            /* Legacy non-atomic cancellation path retained temporarily in source history.
            // If webinar is paid and registration was paid, refund wallet if webinar hasn't started yet
            if (!$webinar->is_free && $registration->payment_status === 'paid') {
                if ($webinar->start_at && $webinar->start_at->isPast()) {
                    return ApiResponseService::errorResponse('لا يمكن إلغاء التسجيل بعد بدء الندوة.', [], 400);
                }

                $amountToRefund = (float) $webinar->price;
                if ($amountToRefund > 0) {
                    try {
                        \App\Services\WalletService::creditWallet(
                            $user->id,
                            $amountToRefund,
                            'webinar_refund',
                            'استرداد رسوم التسجيل في ندوة: ' . $webinar->title,
                            (string) $webinar->id,
                            'webinar',
                            'user'
                        );
                    } catch (\Throwable $e) {
                        Log::error('Failed to refund wallet on webinar cancellation', [
                            'user_id' => $user->id,
                            'webinar_id' => $webinar->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            $registration->delete();

            return ApiResponseService::successResponse('Registration cancelled successfully.');
            */
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to cancel registration: ' . $e->getMessage());
        }
    }
}
