<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportedCurrency;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class CurrencyController extends Controller
{
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['manage_settings', 'manage_plans']);

        $currencies = SupportedCurrency::orderBy('country_name')->get();

        return view('admin.currencies.index', [
            'type_menu' => 'currencies',
            'currencies' => $currencies,
        ]);
    }

    public function update(Request $request, int $id)
    {
        ResponseService::noPermissionThenSendJson('manage_settings');

        $validator = Validator::make($request->all(), [
            'exchange_rate_to_egp' => 'required|numeric|gt:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $currency = SupportedCurrency::findOrFail($id);
        $currency->update([
            'exchange_rate_to_egp' => (float) $request->exchange_rate_to_egp,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['success' => true, 'message' => __('Currency updated successfully')]);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('manage_settings');

        $validator = Validator::make($request->all(), [
            'country_code' => 'required|string|size:2|unique:supported_currencies,country_code',
            'country_name' => 'required|string|max:100',
            'currency_code' => 'required|string|size:3',
            'currency_symbol' => 'required|string|max:10',
            'exchange_rate_to_egp' => 'required|numeric|gt:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        SupportedCurrency::create([
            'country_code' => strtoupper($request->country_code),
            'country_name' => $request->country_name,
            'currency_code' => strtoupper($request->currency_code),
            'currency_symbol' => $request->currency_symbol,
            'exchange_rate_to_egp' => (float) $request->exchange_rate_to_egp,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['success' => true, 'message' => __('Currency added successfully')]);
    }

    public function destroy(int $id)
    {
        ResponseService::noPermissionThenSendJson('manage_settings');

        $currency = SupportedCurrency::findOrFail($id);
        $currency->delete();

        return response()->json(['success' => true, 'message' => __('Currency removed')]);
    }
}
