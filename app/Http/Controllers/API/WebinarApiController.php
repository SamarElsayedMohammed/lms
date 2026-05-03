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
            $query = Webinar::with('instructor:id,name,profile')
                ->whereIn('status', ['scheduled', 'live'])
                ->where('start_at', '>=', now()->subHours(2));

            $perPage = min((int) $request->input('per_page', 15), 50);
            $webinars = $query->orderBy('start_at', 'asc')->paginate($perPage);

            return ApiResponseService::successResponse('Webinars retrieved successfully', $webinars);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve webinars: ' . $e->getMessage());
        }
    }

    /**
     * Get webinar details
     */
    public function show($id)
    {
        try {
            $webinar = Webinar::with(['instructor:id,name,profile', 'registrations'])
                ->where('id', $id)
                ->first();

            if (!$webinar) {
                return ApiResponseService::errorResponse('Webinar not found', [], 404);
            }

            $user = Auth::guard('sanctum')->user();
            $is_registered = $user ? WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->exists() : false;

            // Hide sensitive links if not registered or not completed
            if (!$is_registered) {
                $webinar->makeHidden(['join_url', 'meeting_password']);
            }
            if (!$is_registered || $webinar->status !== 'completed') {
                $webinar->makeHidden(['recording_url']);
            }

            return ApiResponseService::successResponse('Webinar details retrieved', [
                'webinar' => $webinar,
                'is_registered' => $is_registered
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve webinar: ' . $e->getMessage());
        }
    }

    /**
     * Register for a webinar
     */
    public function register(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $webinar = Webinar::findOrFail($id);

            if ($webinar->status === 'completed' || $webinar->status === 'cancelled') {
                return ApiResponseService::errorResponse('This webinar is no longer available for registration.');
            }

            $existing = WebinarRegistration::where('user_id', $user->id)->where('webinar_id', $webinar->id)->first();
            if ($existing) {
                return ApiResponseService::successResponse('You are already registered for this webinar.');
            }

            if (!$webinar->is_free && $webinar->price > 0) {
                $totalAmount = $webinar->price;
                $walletAmount = 0.0;
                $gatewayAmount = $totalAmount;
                $useWallet = $request->boolean('use_wallet');

                if ($useWallet && $user->wallet_balance > 0) {
                    $walletAmount = (float) min($user->wallet_balance, $totalAmount);
                    $gatewayAmount = $totalAmount - $walletAmount;
                }

                // If fully paid by wallet
                if ($gatewayAmount <= 0) {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($user, $webinar, $walletAmount) {
                        \App\Services\WalletService::debitWallet(
                            $user->id,
                            $walletAmount,
                            'webinar_payment',
                            'Paid for webinar: ' . $webinar->title,
                            (string) $webinar->id,
                            'webinar'
                        );

                        WebinarRegistration::create([
                            'user_id' => $user->id,
                            'webinar_id' => $webinar->id,
                            'payment_status' => 'paid',
                        ]);
                    });

                    $user->notify(new \App\Notifications\WebinarRegistrationNotification($webinar));

                    return ApiResponseService::successResponse('Successfully registered for the webinar using wallet.');
                }

                // If requires Kashier gateway
                try {
                    $kashierService = app(\App\Services\Payment\KashierCheckoutService::class);
                    $checkout = $kashierService->createWebinarCheckoutSession($webinar->id, $user, $gatewayAmount);
                } catch (\RuntimeException $e) {
                    return ApiResponseService::errorResponse('Payment gateway is not configured.', [], 503);
                }

                \Illuminate\Support\Facades\Cache::put('kashier_pending_' . $checkout['order_id'], [
                    'wallet_amount' => $walletAmount,
                    'webinar_id' => $webinar->id,
                    'user_id' => $user->id,
                ], 3600); // 1 hour TTL

                // Create a pending registration
                WebinarRegistration::create([
                    'user_id' => $user->id,
                    'webinar_id' => $webinar->id,
                    'payment_status' => 'pending',
                ]);

                return ApiResponseService::successResponse('Please complete payment via Kashier.', [
                    'requires_checkout' => true,
                    'checkout_url' => $checkout['url'],
                    'order_id' => $checkout['order_id'],
                    'payment' => [
                        'total_amount' => $totalAmount,
                        'wallet_amount' => $walletAmount,
                        'gateway_amount' => $gatewayAmount,
                    ],
                ]);
            }

            // Free webinar
            WebinarRegistration::create([
                'user_id' => $user->id,
                'webinar_id' => $webinar->id,
                'payment_status' => 'free',
            ]);

            $user->notify(new \App\Notifications\WebinarRegistrationNotification($webinar));

            return ApiResponseService::successResponse('Successfully registered for the webinar.');
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to register: ' . $e->getMessage());
        }
    }

    /**
     * Join a webinar (get join URL)
     */
    public function join($id)
    {
        try {
            $user = Auth::user();
            $webinar = Webinar::findOrFail($id);

            $registration = WebinarRegistration::where('user_id', $user->id)
                ->where('webinar_id', $webinar->id)
                ->first();

            if (!$registration && !$webinar->is_free) {
                return ApiResponseService::errorResponse('You must register for this webinar first.');
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
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to join webinar: ' . $e->getMessage());
        }
    }
}
