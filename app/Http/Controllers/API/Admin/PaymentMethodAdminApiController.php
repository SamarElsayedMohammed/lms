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
            'type' => 'required|string|in:online,instapay,mobile_wallet,fawry,bank_transfer,manual,other,custom',
            'is_active' => 'nullable|boolean',
            'account_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'iban' => 'nullable|string|max:255',
            'instapay_id' => 'nullable|string|max:255',
            'merchant_code' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:2000',
            'countries' => 'nullable|array',
            'countries.*' => 'string|size:2',
            'currencies' => 'nullable|array',
            'currencies.*' => 'string|max:10',
            'require_receipt' => 'nullable|boolean',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
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

        // Validate that method provides at least some account information or instructions
        if (
            empty($request->account_number) &&
            empty($request->instapay_id) &&
            empty($request->merchant_code) &&
            empty($request->iban) &&
            empty($request->instructions)
        ) {
            return ApiResponseService::validationError('طريقة الدفع يجب أن تتضمن على الأقل رقم حساب أو معرف انستاباي أو IBAN أو تعليمات دفع.');
        }

        $data = $request->except('logo');

        if ($request->hasFile('logo')) {
            $data['logo'] = FileService::compressAndUpload($request->file('logo'), $this->methodFolder);
        } elseif ($request->filled('logo_url')) {
            $data['logo'] = trim((string) $request->input('logo_url'), " \t\n\r\0\x0B'\"`");
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
            'type' => 'sometimes|required|string|in:online,instapay,mobile_wallet,fawry,bank_transfer,manual,other,custom',
            'is_active' => 'nullable|boolean',
            'account_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'iban' => 'nullable|string|max:255',
            'instapay_id' => 'nullable|string|max:255',
            'merchant_code' => 'nullable|string|max:255',
            'instructions' => 'nullable|string|max:2000',
            'countries' => 'nullable|array',
            'countries.*' => 'string|size:2',
            'currencies' => 'nullable|array',
            'currencies.*' => 'string|max:10',
            'require_receipt' => 'nullable|boolean',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
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
            $data['logo'] = trim((string) $request->input('logo_url'), " \t\n\r\0\x0B'\"`");
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
