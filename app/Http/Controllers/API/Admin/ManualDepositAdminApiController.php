<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManualDeposit;
use App\Models\ManualDepositMethod;
use App\Services\ApiResponseService;
use App\Services\FileService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ManualDepositAdminApiController extends AdminCrudApiController
{
    private $methodFolder = 'manual_deposit_methods';

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get all manual deposit methods
     */
    public function indexMethods(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-list'); // Assuming finance permission

        $methods = ManualDepositMethod::all()->map(function ($method) {
            $details = json_decode($method->account_details, true) ?: [];
            return [
                'id' => $method->id,
                'name' => $method->name,
                'type' => $details['type'] ?? 'bank_transfer',
                'account_name' => $details['account_name'] ?? null,
                'account_number' => $details['account_number'] ?? null,
                'instapay_id' => $details['instapay_id'] ?? null,
                'merchant_code' => $details['merchant_code'] ?? null,
                'instructions' => $method->instructions,
                'is_active' => $method->is_active,
                'countries' => $method->countries,
                'image' => $method->image,
                'currency' => $method->currency,
                'min_amount' => $method->min_amount,
                'max_amount' => $method->max_amount,
                'fixed_fee' => $method->fixed_fee,
                'percent_fee' => $method->percent_fee,
                'dynamic_fields' => $method->dynamic_fields,
            ];
        });
        return ApiResponseService::successResponse('Manual deposit methods retrieved successfully', $methods);
    }

    /**
     * Store a new manual deposit method
     */
    public function storeMethod(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-create');

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'image_url' => 'nullable|url|max:2048',
            'instructions' => 'nullable|string',
            'countries' => 'nullable|array',
            'countries.*' => 'nullable|string|max:10',
            'is_active' => 'nullable|boolean',
            'type' => 'nullable|string',
            'account_name' => 'nullable|string',
            'account_number' => 'nullable|string',
            'instapay_id' => 'nullable|string',
            'merchant_code' => 'nullable|string',
            'currency' => 'nullable|string|max:10',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'fixed_fee' => 'nullable|numeric|min:0',
            'percent_fee' => 'nullable|numeric|min:0|max:100',
            'dynamic_fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $accountDetails = json_encode([
            'type' => $request->input('type'),
            'account_name' => $request->input('account_name'),
            'account_number' => $request->input('account_number'),
            'instapay_id' => $request->input('instapay_id'),
            'merchant_code' => $request->input('merchant_code'),
        ]);

        $data = $request->only([
            'name', 'instructions', 'countries', 'is_active',
            'currency', 'min_amount', 'max_amount', 'fixed_fee', 'percent_fee', 'dynamic_fields'
        ]);
        $data['account_details'] = $accountDetails;
        
        if ($request->hasFile('image')) {
            $data['image'] = FileService::compressAndUpload($request->file('image'), $this->methodFolder);
        } elseif ($request->filled('image_url')) {
            $data['image'] = $request->input('image_url');
        }

        $method = ManualDepositMethod::create($data);

        return ApiResponseService::successResponse('Manual deposit method created successfully', $method);
    }

    /**
     * Update an existing manual deposit method
     */
    public function updateMethod(Request $request, $id)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-edit');

        $method = ManualDepositMethod::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'image_url' => 'nullable|url|max:2048',
            'instructions' => 'nullable|string',
            'countries' => 'nullable|array',
            'countries.*' => 'nullable|string|max:10',
            'is_active' => 'nullable|boolean',
            'type' => 'nullable|string',
            'account_name' => 'nullable|string',
            'account_number' => 'nullable|string',
            'instapay_id' => 'nullable|string',
            'merchant_code' => 'nullable|string',
            'currency' => 'nullable|string|max:10',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'fixed_fee' => 'nullable|numeric|min:0',
            'percent_fee' => 'nullable|numeric|min:0|max:100',
            'dynamic_fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $accountDetails = json_encode([
            'type' => $request->input('type'),
            'account_name' => $request->input('account_name'),
            'account_number' => $request->input('account_number'),
            'instapay_id' => $request->input('instapay_id'),
            'merchant_code' => $request->input('merchant_code'),
        ]);

        $data = $request->only([
            'name', 'instructions', 'countries', 'is_active',
            'currency', 'min_amount', 'max_amount', 'fixed_fee', 'percent_fee', 'dynamic_fields'
        ]);
        $data['account_details'] = $accountDetails;

        if ($request->hasFile('image')) {
            $data['image'] = FileService::compressAndReplace($request->file('image'), $this->methodFolder, $method->getRawOriginal('image'));
        } elseif ($request->filled('image_url')) {
            $data['image'] = $request->input('image_url');
        }

        $method->update($data);

        return ApiResponseService::successResponse('Manual deposit method updated successfully', $method);
    }

    /**
     * Delete a manual deposit method
     */
    public function destroyMethod($id)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-delete');

        $method = ManualDepositMethod::findOrFail($id);
        
        if ($method->image) {
            FileService::delete($method->getRawOriginal('image'));
        }

        $method->delete();

        return ApiResponseService::successResponse('Manual deposit method deleted successfully');
    }

    /**
     * Get all manual deposit requests
     */
    public function indexDeposits(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-list');

        $query = ManualDeposit::with(['user', 'method']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $deposits = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return ApiResponseService::successResponse('Manual deposits retrieved successfully', $deposits);
    }

    /**
     * Update manual deposit status (approve/reject)
     */
    public function updateDepositStatus(Request $request, $id)
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-edit');

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:approved,rejected',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $deposit = ManualDeposit::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($deposit->status !== 'pending') {
                DB::rollBack();
                return ApiResponseService::errorResponse('This deposit request has already been processed.');
            }

            $deposit->status = $request->status;
            $deposit->admin_notes = $request->admin_notes;

            if ($request->status === 'approved') {
                // Credit user wallet
                $walletService = app(WalletService::class);
                
                $method = $deposit->method;
                $fixedFee = (float) ($method->fixed_fee ?? 0);
                $percentFee = (float) ($method->percent_fee ?? 0);
                
                $feeAmount = round($fixedFee + ($deposit->amount * ($percentFee / 100)), 2);
                $netAmount = round(max(0, $deposit->amount - $feeAmount), 2);

                $deposit->fee_amount = $feeAmount;
                $deposit->net_amount = $netAmount;

                $walletService->creditWallet(
                    $deposit->user_id,
                    $netAmount,
                    'deposit',
                    'Manual Deposit Approved',
                    $deposit->id,
                    ManualDeposit::class
                );
            }

            $deposit->save();
            DB::commit();

            // Send Notification to user safely after commit
            try {
                $deposit->user->notify(new \App\Notifications\ManualDepositStatusNotification($deposit));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send ManualDepositStatusNotification', [
                    'deposit_id' => $deposit->id,
                    'error' => $e->getMessage()
                ]);
            }

            return ApiResponseService::successResponse('Manual deposit request updated successfully', $deposit);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseService::errorResponse('Failed to update deposit status: ' . $e->getMessage());
        }
    }
}
