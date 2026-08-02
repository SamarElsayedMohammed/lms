<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalMethod;
use App\Services\ApiResponseService;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WithdrawalMethodAdminApiController extends AdminCrudApiController
{
    private $methodFolder = 'withdrawal_methods';

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get all withdrawal methods
     */
    public function index(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-list'); 

        $methods = WithdrawalMethod::all();
        
        return ApiResponseService::successResponse('Withdrawal methods retrieved successfully', $methods);
    }

    /**
     * Store a new withdrawal method
     */
    public function store(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-create');

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:withdrawal_methods,code',
            'currency' => 'nullable|string|max:10',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'fixed_fee' => 'nullable|numeric|min:0',
            'percent_fee' => 'nullable|numeric|min:0|max:100',
            'estimated_delay' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'dynamic_fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $data = $request->except('image');
        
        if (!isset($data['currency'])) {
            $data['currency'] = 'EGP';
        }

        if ($request->hasFile('image')) {
            $data['image'] = FileService::compressAndUpload($request->file('image'), $this->methodFolder);
        }

        $method = WithdrawalMethod::create($data);

        return ApiResponseService::successResponse('Withdrawal method created successfully', $method);
    }

    /**
     * Update an existing withdrawal method
     */
    public function update(Request $request, $id)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-edit');

        $method = WithdrawalMethod::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:withdrawal_methods,code,' . $method->id,
            'currency' => 'nullable|string|max:10',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'fixed_fee' => 'nullable|numeric|min:0',
            'percent_fee' => 'nullable|numeric|min:0|max:100',
            'estimated_delay' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'dynamic_fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $data = $request->except('image');

        if ($request->hasFile('image')) {
            $data['image'] = FileService::compressAndReplace($request->file('image'), $this->methodFolder, $method->getRawOriginal('image'));
        }

        $method->update($data);

        return ApiResponseService::successResponse('Withdrawal method updated successfully', $method);
    }
    /**
     * Archive/Delete a withdrawal method
     */
    public function archive($id)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-delete');

        $method = WithdrawalMethod::findOrFail($id);
        
        // Soft delete could be implemented by setting is_active = false, but the frontend calls this archive or delete
        $method->is_active = false;
        $method->save();

        return ApiResponseService::successResponse('Withdrawal method archived successfully');
    }

    /**
     * Toggle active status of a withdrawal method
     */
    public function toggle($id)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-edit');

        $method = WithdrawalMethod::findOrFail($id);
        $method->is_active = !$method->is_active;
        $method->save();

        return ApiResponseService::successResponse('Withdrawal method status toggled successfully', $method);
    }
}
