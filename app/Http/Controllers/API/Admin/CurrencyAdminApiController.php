<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\SupportedCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CurrencyAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-list');

        $currencies = SupportedCurrency::orderBy('country_name')->get();

        return $this->jsonSuccess(__('Currencies retrieved'), $currencies);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-list');

        $currency = SupportedCurrency::find($id);
        if (!$currency) {
            return $this->jsonError(__('Currency not found'), 404);
        }

        return $this->jsonSuccess(__('Currency retrieved'), $currency);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-list');

        $validator = Validator::make($request->all(), [
            'country_code' => 'required|string|size:2|unique:supported_currencies,country_code',
            'country_name' => 'required|string|max:100',
            'currency_code' => 'required|string|size:3',
            'currency_symbol' => 'required|string|max:10',
            'exchange_rate_to_egp' => 'required|numeric|gt:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $currency = SupportedCurrency::create([
            'country_code' => strtoupper($request->country_code),
            'country_name' => $request->country_name,
            'currency_code' => strtoupper($request->currency_code),
            'currency_symbol' => $request->currency_symbol,
            'exchange_rate_to_egp' => (float) $request->exchange_rate_to_egp,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->jsonSuccess(__('Currency added successfully'), $currency, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-list');

        $currency = SupportedCurrency::find($id);
        if (!$currency) {
            return $this->jsonError(__('Currency not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'country_code' => 'sometimes|string|size:2|unique:supported_currencies,country_code,' . $id,
            'country_name' => 'sometimes|string|max:100',
            'currency_code' => 'sometimes|string|size:3',
            'currency_symbol' => 'sometimes|string|max:10',
            'exchange_rate_to_egp' => 'sometimes|numeric|gt:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper($data['country_code']);
        }
        if (isset($data['currency_code'])) {
            $data['currency_code'] = strtoupper($data['currency_code']);
        }
        $currency->update($data);

        return $this->jsonSuccess(__('Currency updated successfully'), $currency->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-list');

        $currency = SupportedCurrency::find($id);
        if (!$currency) {
            return $this->jsonError(__('Currency not found'), 404);
        }

        $currency->delete();
        return $this->jsonSuccess(__('Currency deleted successfully'));
    }

    /**
     * Refresh exchange rates manually.
     */
    public function refreshRates(): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-list');

        try {
            \App\Jobs\UpdateExchangeRatesJob::dispatchSync();
            return $this->jsonSuccess(__('Exchange rates updated successfully'), [
                'last_updated_at' => now()->toDateTimeString()
            ]);
        } catch (\Exception $e) {
            return $this->jsonError(__('Failed to update rates: ') . $e->getMessage(), 500);
        }
    }
}
