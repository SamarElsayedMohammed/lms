<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Setting;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Payment Gateway Settings Admin API Controller
 *
 * Manages payment gateway credentials (Kashier, Stripe, Razorpay, Flutterwave, Paymob)
 * for the SPA admin dashboard.
 */
class PaymentGatewaySettingsAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET /api/admin/settings/payment-gateways
     * Returns all payment gateway configurations
     */
    public function index(): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-payment-gateway-list');

        $settingKeys = [
            // Kashier
            'kashier_status', 'kashier_merchant_id', 'kashier_api_key',
            'kashier_iframe_key', 'kashier_mode',
            // Stripe
            'stripe_status', 'stripe_publishable_key', 'stripe_secret_key',
            'stripe_webhook_secret', 'stripe_currency',
            // Razorpay
            'razorpay_status', 'razorpay_api_key', 'razorpay_secret_key',
            'razorpay_webhook_url', 'razorpay_webhook_secret_key',
            // Flutterwave
            'flutterwave_status', 'flutterwave_public_key', 'flutterwave_secret_key',
            'flutterwave_encryption_key',
            // Paymob
            'paymob_status', 'paymob_api_key', 'paymob_integration_id',
            'paymob_hmac_secret',
        ];

        $settings = Setting::whereIn('name', $settingKeys)
            ->get()
            ->keyBy('name')
            ->map(fn($s) => $s->getRawOriginal('value') ?? $s->value);

        $gateways = [
            'kashier' => [
                'enabled'       => (bool) ($settings['kashier_status'] ?? false),
                'merchant_id'   => $settings['kashier_merchant_id'] ?? '',
                'api_key'       => $settings['kashier_api_key'] ?? '',
                'iframe_key'    => $settings['kashier_iframe_key'] ?? '',
                'mode'          => $settings['kashier_mode'] ?? 'live', // live or test
            ],
            'stripe' => [
                'enabled'           => (bool) ($settings['stripe_status'] ?? false),
                'publishable_key'   => $settings['stripe_publishable_key'] ?? '',
                'secret_key'        => $settings['stripe_secret_key'] ?? '',
            ],
            'razorpay' => [
                'enabled' => (bool) ($settings['razorpay_status'] ?? false),
                'api_key' => $settings['razorpay_api_key'] ?? '',
                'secret_key' => $settings['razorpay_secret_key'] ?? '',
                'webhook_url' => $settings['razorpay_webhook_url'] ?? '',
                'webhook_secret_key' => $settings['razorpay_webhook_secret_key'] ?? '',
            ],
            'flutterwave' => [
                'enabled'        => (bool) ($settings['flutterwave_status'] ?? false),
                'public_key'     => $settings['flutterwave_public_key'] ?? '',
                'secret_key'     => $settings['flutterwave_secret_key'] ?? '',
                'encryption_key' => $settings['flutterwave_encryption_key'] ?? '',
            ],
            'paymob' => [
                'enabled'        => (bool) ($settings['paymob_status'] ?? false),
                'api_key'        => $settings['paymob_api_key'] ?? '',
                'integration_id' => $settings['paymob_integration_id'] ?? '',
                'hmac_secret'    => $settings['paymob_hmac_secret'] ?? '',
            ],
        ];

        return ApiResponseService::successResponse('Payment gateway settings retrieved', $gateways);
    }

    /**
     * PUT /api/admin/settings/payment-gateways
     * Updates payment gateway credentials
     */
    public function update(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-payment-gateway-edit');

        $validator = Validator::make($request->all(), [
            'gateway' => 'required|in:kashier,stripe,razorpay,flutterwave,paymob',

            // Kashier fields
            'kashier_merchant_id'  => 'sometimes|nullable|string|max:255',
            'kashier_api_key'      => 'sometimes|nullable|string|max:500',
            'kashier_iframe_key'   => 'sometimes|nullable|string|max:500',
            'kashier_mode'         => 'sometimes|in:live,test',
            'kashier_status'       => 'sometimes|boolean',

            // Stripe fields
            'stripe_publishable_key' => 'sometimes|nullable|string|max:255',
            'stripe_secret_key'      => 'sometimes|nullable|string|max:255',
            'stripe_status'          => 'sometimes|boolean',

            // Razorpay fields
            'razorpay_api_key' => 'sometimes|nullable|string|max:255',
            'razorpay_secret_key' => 'sometimes|nullable|string|max:255',
            'razorpay_webhook_url' => 'sometimes|nullable|string|max:500',
            'razorpay_webhook_secret_key' => 'sometimes|nullable|string|max:255',
            'razorpay_status' => 'sometimes|boolean',
            // Backward-compatible aliases
            'razorpay_key' => 'sometimes|nullable|string|max:255',
            'razorpay_secret' => 'sometimes|nullable|string|max:255',

            // Flutterwave fields
            'flutterwave_public_key'     => 'sometimes|nullable|string|max:255',
            'flutterwave_secret_key'     => 'sometimes|nullable|string|max:255',
            'flutterwave_encryption_key' => 'sometimes|nullable|string|max:255',
            'flutterwave_status'         => 'sometimes|boolean',

            // Paymob fields
            'paymob_api_key'        => 'sometimes|nullable|string|max:500',
            'paymob_integration_id' => 'sometimes|nullable|string|max:255',
            'paymob_hmac_secret'    => 'sometimes|nullable|string|max:255',
            'paymob_status'         => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $gateway = $request->input('gateway');

        // Map of gateway -> setting keys to persist
        $gatewayMap = [
            'kashier' => [
                'kashier_status', 'kashier_merchant_id', 'kashier_api_key',
                'kashier_iframe_key', 'kashier_mode',
            ],
            'stripe' => [
                'stripe_status', 'stripe_publishable_key', 'stripe_secret_key',
            ],
            'razorpay' => [
                'razorpay_status', 'razorpay_api_key', 'razorpay_secret_key',
                'razorpay_webhook_url', 'razorpay_webhook_secret_key',
            ],
            'flutterwave' => [
                'flutterwave_status', 'flutterwave_public_key', 'flutterwave_secret_key',
                'flutterwave_encryption_key',
            ],
            'paymob' => [
                'paymob_status', 'paymob_api_key', 'paymob_integration_id',
                'paymob_hmac_secret',
            ],
        ];

        $keysToUpdate = $gatewayMap[$gateway] ?? [];

        if ($gateway === 'razorpay') {
            if ($request->has('razorpay_key') && !$request->has('razorpay_api_key')) {
                $request->merge(['razorpay_api_key' => $request->input('razorpay_key')]);
            }
            if ($request->has('razorpay_secret') && !$request->has('razorpay_secret_key')) {
                $request->merge(['razorpay_secret_key' => $request->input('razorpay_secret')]);
            }
        }

        foreach ($keysToUpdate as $key) {
            if ($request->has($key)) {
                $value = is_bool($request->input($key))
                    ? (int) $request->boolean($key)
                    : $request->input($key, '');

                Setting::updateOrCreate(
                    ['name' => $key],
                    ['value' => (string) $value, 'type' => 'text']
                );
            }
        }

        // Clear cache so changes take effect immediately
        if (function_exists('cache')) {
            cache()->forget('system_settings');
        }

        return ApiResponseService::successResponse('Payment gateway settings updated successfully');
    }
}
