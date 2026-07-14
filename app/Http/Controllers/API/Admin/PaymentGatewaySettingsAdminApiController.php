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
    private array $supportedGateways = ['stripe', 'paypal', 'paymob', 'tap', 'myfatoorah', 'fawry'];

    /**
     * Get all payment gateway settings
     */
    public function index(Request $request)
    {
        $this->checkPermission('settings-payment-gateway-list');
        
        $settings = Setting::whereIn('name', array_map(fn($g) => "payment_gateway_{$g}", $this->supportedGateways))
            ->get()
            ->keyBy('name');

        $gateways = [];
        foreach ($this->supportedGateways as $gateway) {
            $key = "payment_gateway_{$gateway}";
            $gateways[$gateway] = $settings->has($key) 
                ? json_decode($settings[$key]->value, true) 
                : ['enabled' => false, 'environment' => 'sandbox', 'keys' => []];
        }

        return ApiResponseService::successResponse('Payment gateways retrieved successfully', $gateways);
    }

    /**
     * Update a specific payment gateway
     */
    public function update(Request $request)
    {
        $this->checkPermission('settings-payment-gateway-edit');

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

        CachingService::removeCache(config('constants.CACHE.SETTINGS'));

        return ApiResponseService::successResponse(ucfirst($gateway) . ' gateway settings updated successfully', $payload);
    }
}
