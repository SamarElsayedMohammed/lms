<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ApiService
{
    public static function validateRequest(Request $request, array $rules)
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            ApiResponseService::validationError($validator->errors()->first());
        }
    }

    public static function verifyFirebaseToken(#[\SensitiveParameter] string $token)
    {
        try {
            $verifiedToken = HelperService::verifyToken($token);
            if (empty($verifiedToken)) {
                ApiResponseService::errorResponse('Invalid Firebase token');
            }
            return $verifiedToken;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Firebase token error: ' . $e->getMessage());
            ApiResponseService::errorResponse('Invalid Firebase token');
        }
    }

    public static function removeUserFromFirebase(#[\SensitiveParameter] string $token)
    {
        $verifiedToken = self::verifyFirebaseToken($token); // Verify token
        $firebaseId = $verifiedToken->claims()->get('sub');
        HelperService::removeUserFromFirebase($firebaseId); // Remove user from firebase
        return $firebaseId;
    }

    public static function getGeneralSystemSettings()
    {
        $settings = HelperService::systemSettings([
            'system_color',
            'currency_code',
            'currency_symbol',
        ]);
        $settings['currency_code'] = !empty($settings['currency_code']) ? $settings['currency_code'] : 'EGP';
        $settings['currency_symbol'] = !empty($settings['currency_symbol']) ? $settings['currency_symbol'] : 'ج.م';
        $settings['active_payment_settings'] = self::getActivePaymentDetails();
        $settings['affiliate_system'] = app(\App\Services\FeatureFlagService::class)->isEnabled('affiliate_system', false);
        return $settings;
    }

    public static function getActivePaymentDetails()
    {
        // Get all payment gateway statuses and keys
        $paymentSettings = HelperService::systemSettings([
            'razorpay_status',
            'stripe_status',
            'flutterwave_status',
            'kashier_status',
        ]);

        $paymentDetails = [];
        $counter = 0;

        // Check Razorpay
        if (!empty($paymentSettings['razorpay_status']) && $paymentSettings['razorpay_status'] == 1) {
            $paymentDetails[$counter] = [
                'payment_gateway' => 'razorpay',
            ];
            $counter++;
        }

        // Check Stripe
        if (!empty($paymentSettings['stripe_status']) && $paymentSettings['stripe_status'] == 1) {
            $paymentDetails[$counter] = [
                'payment_gateway' => 'stripe',
            ];
            $counter++;
        }

        // Check Flutterwave
        if (!empty($paymentSettings['flutterwave_status']) && $paymentSettings['flutterwave_status'] == 1) {
            $paymentDetails[$counter] = [
                'payment_gateway' => 'flutterwave',
            ];
            $counter++;
        }

        // Check Kashier
        if (!empty($paymentSettings['kashier_status']) && $paymentSettings['kashier_status'] == 1) {
            $paymentDetails[$counter] = [
                'payment_gateway' => 'kashier',
            ];
            $counter++;
        }

        return $paymentDetails;
    }
}
