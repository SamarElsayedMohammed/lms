<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\ApiResponseService;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentMethodAdminApiController extends AdminCrudApiController
{
    private $methodFolder = 'payment_methods';

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get all payment methods
     */
    public function index(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-list'); 

        $methods = PaymentMethod::query()->orderBy('sort_order')->orderBy('name')->get();
        
        return ApiResponseService::successResponse('Payment methods retrieved successfully', $methods);
    }

    /**
     * Store a new payment method
     */
    public function store(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-create');

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:online,instapay,mobile_wallet,fawry,bank_transfer',
            'is_active' => 'nullable|boolean',
            'account_name' => 'nullable|string',
            'account_number' => 'nullable|string',
            'instapay_id' => 'nullable|string',
            'merchant_code' => 'nullable|string',
            'instructions' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'logo_url' => 'nullable|string|max:2048',
            'dynamic_fields' => 'nullable|array',
            'dynamic_fields.*.key' => 'required_with:dynamic_fields|string|max:64|regex:/^[A-Za-z][A-Za-z0-9_]*$/',
            'dynamic_fields.*.label' => 'required_with:dynamic_fields|string|max:255',
            'dynamic_fields.*.type' => 'required_with:dynamic_fields|in:text,number,email,textarea',
            'dynamic_fields.*.required' => 'nullable|boolean',
            'dynamic_fields.*.validation' => 'nullable|in:alphanumeric,phone,reference',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $data = $request->except('logo');

        if ($request->hasFile('logo')) {
            $data['logo'] = FileService::compressAndUpload($request->file('logo'), $this->methodFolder);
        } elseif ($request->filled('logo_url')) {
            $data['logo'] = trim((string) $request->input('logo_url'));
        }
        unset($data['logo_url']);

        $method = PaymentMethod::create($data);

        return ApiResponseService::successResponse('Payment method created successfully', $method);
    }

    /**
     * Update an existing payment method
     */
    public function update(Request $request, $id)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-edit');

        $method = PaymentMethod::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string|in:online,instapay,mobile_wallet,fawry,bank_transfer',
            'is_active' => 'nullable|boolean',
            'account_name' => 'nullable|string',
            'account_number' => 'nullable|string',
            'instapay_id' => 'nullable|string',
            'merchant_code' => 'nullable|string',
            'instructions' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'logo_url' => 'nullable|string|max:2048',
            'dynamic_fields' => 'nullable|array',
            'dynamic_fields.*.key' => 'required_with:dynamic_fields|string|max:64|regex:/^[A-Za-z][A-Za-z0-9_]*$/',
            'dynamic_fields.*.label' => 'required_with:dynamic_fields|string|max:255',
            'dynamic_fields.*.type' => 'required_with:dynamic_fields|in:text,number,email,textarea',
            'dynamic_fields.*.required' => 'nullable|boolean',
            'dynamic_fields.*.validation' => 'nullable|in:alphanumeric,phone,reference',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $data = $request->except('logo');

        if ($request->hasFile('logo')) {
            $data['logo'] = FileService::compressAndReplace($request->file('logo'), $this->methodFolder, $method->getRawOriginal('logo'));
        } elseif ($request->filled('logo_url')) {
            $data['logo'] = trim((string) $request->input('logo_url'));
        }
        unset($data['logo_url']);

        $method->update($data);

        return ApiResponseService::successResponse('Payment method updated successfully', $method);
    }

    /**
     * Delete a payment method
     */
    public function destroy($id)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-delete');

        $method = PaymentMethod::findOrFail($id);
        
        if ($method->logo) {
            FileService::delete($method->getRawOriginal('logo'));
        }

        $method->delete();

        return ApiResponseService::successResponse('Payment method deleted successfully');
    }
}
