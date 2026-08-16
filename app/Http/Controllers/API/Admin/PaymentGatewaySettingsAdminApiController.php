<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ApiResponseService;
use App\Services\CachingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PaymentGatewaySettingsAdminApiController extends AdminCrudApiController
{
    private array $supportedGateways = ['kashier', 'stripe', 'paypal', 'paymob', 'tap', 'myfatoorah', 'fawry'];

    /**
     * Get all payment gateway settings including global automatic/manual flags
     */
    public function index(Request $request)
    {
        $this->checkPermission('settings-payment-gateway-list');
        
        $settingNames = array_merge(
            array_map(fn($g) => "payment_gateway_{$g}", $this->supportedGateways),
            ['automatic_payments_enabled', 'manual_payments_enabled', 'kashier_enabled']
        );

        $settings = Setting::whereIn('name', $settingNames)
            ->get()
            ->keyBy('name');

        $gateways = [];
        foreach ($this->supportedGateways as $gateway) {
            $key = "payment_gateway_{$gateway}";
            $gateways[$gateway] = $settings->has($key) 
                ? json_decode($settings[$key]->value, true) 
                : ['enabled' => false, 'environment' => 'sandbox', 'keys' => []];
        }

        $autoEnabledSetting = $settings->get('automatic_payments_enabled');
        $manualEnabledSetting = $settings->get('manual_payments_enabled');

        $automaticPaymentsEnabled = $autoEnabledSetting !== null ? filter_var($autoEnabledSetting->value, FILTER_VALIDATE_BOOLEAN) : false;
        $manualPaymentsEnabled = $manualEnabledSetting !== null ? filter_var($manualEnabledSetting->value, FILTER_VALIDATE_BOOLEAN) : true;

        $response = array_merge($gateways, [
            'automatic_payments_enabled' => $automaticPaymentsEnabled,
            'manual_payments_enabled' => $manualPaymentsEnabled,
            '_meta' => [
                'automatic_payments_enabled' => $automaticPaymentsEnabled,
                'manual_payments_enabled' => $manualPaymentsEnabled,
                'supported_gateways' => $this->supportedGateways,
            ],
        ]);

        return ApiResponseService::successResponse('Payment gateways retrieved successfully', $response);
    }

    /**
     * Update a specific payment gateway or global settings
     */
    public function update(Request $request)
    {
        $this->checkPermission('settings-payment-gateway-edit');

        // Handle global toggles if present
        if ($request->has('automatic_payments_enabled')) {
            $autoVal = $request->boolean('automatic_payments_enabled');
            Setting::updateOrCreate(
                ['name' => 'automatic_payments_enabled'],
                ['value' => $autoVal ? '1' : '0', 'type' => 'boolean']
            );
        }

        if ($request->has('manual_payments_enabled')) {
            $manualVal = $request->boolean('manual_payments_enabled');
            Setting::updateOrCreate(
                ['name' => 'manual_payments_enabled'],
                ['value' => $manualVal ? '1' : '0', 'type' => 'boolean']
            );
        }

        if ($request->filled('gateway')) {
            $request->validate([
                'gateway' => 'required|string|in:' . implode(',', $this->supportedGateways),
                'enabled' => 'required|boolean',
                'environment' => 'required|string|in:sandbox,live',
                'keys' => 'nullable|array',
            ]);

            $gateway = $request->input('gateway');
            $settingName = "payment_gateway_{$gateway}";

            $payload = [
                'enabled' => (bool) $request->input('enabled'),
                'environment' => $request->input('environment'),
                'keys' => $request->input('keys', []),
            ];

            Setting::updateOrCreate(
                ['name' => $settingName],
                ['value' => json_encode($payload), 'type' => 'json']
            );
        }

        CachingService::removeCache(config('constants.CACHE.SETTINGS'));

        return ApiResponseService::successResponse('Payment gateway settings updated successfully', [
            'automatic_payments_enabled' => filter_var(Setting::where('name', 'automatic_payments_enabled')->value('value') ?? false, FILTER_VALIDATE_BOOLEAN),
            'manual_payments_enabled' => filter_var(Setting::where('name', 'manual_payments_enabled')->value('value') ?? true, FILTER_VALIDATE_BOOLEAN),
        ]);
    }
}
