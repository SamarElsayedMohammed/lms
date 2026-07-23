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
            return [
                'id' => $method->id,
                'name' => $method->name,
                'account_details' => $method->account_details,
                'is_active' => $method->is_active,
                'image' => $method->image,
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
            // Accept absolute, relative, CDN, storage and deep links. Uploaded
            // images are handled separately by the `image` file rule below.
            'image_url' => 'nullable|string|max:2048',
            'account_details' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $data = $request->only([
            'name', 'account_details', 'instructions', 'is_active'
        ]);
        
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
            'image_url' => 'nullable|string|max:2048',
            'account_details' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $data = $request->only([
            'name', 'account_details', 'instructions', 'is_active'
        ]);

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

        $deposits->getCollection()->transform(function ($deposit) {
            $depositArray = $deposit->toArray();
            $depositArray['original_amount'] = $deposit->amount;
            $depositArray['original_currency'] = $deposit->currency_code ?? 'EGP';
            $depositArray['amount_egp'] = $deposit->amount_egp ?? $deposit->amount;
            $depositArray['amount'] = $deposit->amount_egp ?? $deposit->amount; // Make the primary amount EGP
            return $depositArray;
        });

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
                
                $netAmount = round($deposit->amount_egp ?? $deposit->amount, 2);

                if (\Illuminate\Support\Facades\Schema::hasColumn('manual_deposits', 'fee_amount')) {
                    $deposit->fee_amount = 0;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('manual_deposits', 'net_amount')) {
                    $deposit->net_amount = $netAmount;
                }

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

    /** Return sensitive deposit evidence only to authorized finance staff. */
    public function downloadReceipt(int $id): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('finance-list');

        $deposit = ManualDeposit::find($id);
        if (!$deposit || !$deposit->getRawOriginal('receipt')) {
            return ApiResponseService::errorResponse('Receipt not found.', [], 404);
        }

        $receipt = $deposit->getRawOriginal('receipt');
        if (!FileService::checkPrivateFileExists($receipt)) {
            return ApiResponseService::errorResponse('Receipt is unavailable.', [], 404);
        }

        return response()->file(FileService::getPrivateFilePath($receipt));
    }
}
