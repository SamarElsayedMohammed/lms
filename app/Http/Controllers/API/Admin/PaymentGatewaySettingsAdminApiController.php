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
            ],
            'stripe' => [
                'enabled'           => (bool) ($settings['stripe_status'] ?? false),
            ],
            'razorpay' => [
                'enabled' => (bool) ($settings['razorpay_status'] ?? false),
            ],
            'flutterwave' => [
                'enabled'        => (bool) ($settings['flutterwave_status'] ?? false),
            ],
            'paymob' => [
                'enabled'        => (bool) ($settings['paymob_status'] ?? false),
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

            // Only allow updating status fields
            'kashier_status'       => 'sometimes|boolean',
            'stripe_status'          => 'sometimes|boolean',
            'razorpay_status' => 'sometimes|boolean',
            'flutterwave_status'         => 'sometimes|boolean',
            'paymob_status'         => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $gateway = $request->input('gateway');

        // Map of gateway -> setting keys to persist
        $gatewayMap = [
            'kashier' => ['kashier_status'],
            'stripe' => ['stripe_status'],
            'razorpay' => ['razorpay_status'],
            'flutterwave' => ['flutterwave_status'],
            'paymob' => ['paymob_status'],
        ];

        $keysToUpdate = $gatewayMap[$gateway] ?? [];

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
