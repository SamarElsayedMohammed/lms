<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ManualDeposit;
use App\Models\ManualDepositMethod;
use App\Models\User;
use App\Notifications\AdminNewManualDepositNotification;
use App\Services\ApiResponseService;
use App\Services\FileService;
use App\Services\GeoLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ManualDepositApiController extends Controller
{
    private $receiptFolder = 'manual_deposits/receipts';
    private $geoLocationService;

    public function __construct(GeoLocationService $geoLocationService)
    {
        $this->geoLocationService = $geoLocationService;
    }

    /**
     * Get active manual deposit methods for user
     */
    public function getMethods(Request $request)
    {
        $user = Auth::user();
        $countryCode = $user?->country_code ?? $this->geoLocationService->getCountryCodeFromRequest($request);

        $query = ManualDepositMethod::where('is_active', true);

        if ($countryCode) {
            $query->where(function ($q) use ($countryCode) {
                $q->whereJsonContains('countries', $countryCode)
                  ->orWhereNull('countries')
                  ->orWhere('countries', '[]');
            });
        }

        $methods = $query->get();
        return ApiResponseService::successResponse('Manual deposit methods retrieved successfully', $methods);
    }

    /**
     * Submit a manual deposit request
     */
    public function submitDeposit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'method_id' => 'required|exists:manual_deposit_methods,id',
            'amount' => 'required|numeric|min:1',
            'transaction_id' => 'nullable|string|max:255',
            'receipt' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'submitted_fields' => 'nullable|string',
            'submitted_files' => 'nullable|array',
            'submitted_files.*' => 'nullable|file|mimes:jpeg,png,jpg,webp,pdf,doc,docx|max:5120'
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $user = Auth::user();
        
        $hasPending = ManualDeposit::where('user_id', $user->id)
            ->where('manual_deposit_method_id', $request->method_id)
            ->where('status', 'pending')
            ->exists();
        
        if ($hasPending) {
            return ApiResponseService::errorResponse('You already have a pending deposit request. Please wait for it to be processed.');
        }

        $method = ManualDepositMethod::find($request->method_id);
        if (!$method || !$method->is_active) {
            return ApiResponseService::errorResponse('This payment method is not available.');
        }

        if ($method->min_amount > 0 && $request->amount < $method->min_amount) {
            return ApiResponseService::errorResponse("The minimum deposit amount is {$method->min_amount}.");
        }

        if ($method->max_amount > 0 && $request->amount > $method->max_amount) {
            return ApiResponseService::errorResponse("The maximum deposit amount is {$method->max_amount}.");
        }

        try {
            $receiptPath = null;
            if ($request->hasFile('receipt')) {
                $receiptPath = FileService::compressAndUpload($request->file('receipt'), $this->receiptFolder, 'public');
            }

            $submittedFields = [];
            if ($request->filled('submitted_fields')) {
                $submittedFields = json_decode($request->input('submitted_fields'), true) ?: [];
                
                // Validate dynamic fields against schema
                $dynamicFieldsSchema = is_string($method->dynamic_fields) ? json_decode($method->dynamic_fields, true) : ($method->dynamic_fields ?: []);
                $validatedSubmittedFields = [];
                
                foreach ($dynamicFieldsSchema as $fieldDef) {
                    $defId = $fieldDef['id'] ?? $fieldDef['name'] ?? null;
                    if (!$defId) continue;
                    
                    // Find the submitted field by ID or Label
                    $submittedField = collect($submittedFields)->first(function ($f) use ($defId, $fieldDef) {
                        return ($f['fieldId'] ?? $f['field_id'] ?? '') == $defId 
                            || ($f['fieldLabel'] ?? $f['field_name'] ?? '') == ($fieldDef['label'] ?? '');
                    });

                    // Check if required
                    $files = $request->file('submitted_files') ?: [];
                    $hasFile = isset($files[$defId]) || isset($files[$fieldDef['name'] ?? '']);
                    if (!empty($fieldDef['required']) && empty($submittedField['value']) && !$hasFile) {
                        return ApiResponseService::errorResponse("Field {$fieldDef['label']} is required.");
                    }

                    if ($submittedField || $hasFile) {
                        if (!$submittedField) {
                            $submittedField = [
                                'fieldId' => $defId,
                                'fieldLabel' => $fieldDef['label'] ?? '',
                                'fieldType' => $fieldDef['type'] ?? 'text',
                                'value' => null
                            ];
                        }
                        $validatedSubmittedFields[] = $submittedField;
                    }
                }
                $submittedFields = $validatedSubmittedFields;
                
                // Handle files if any
                if ($request->hasFile('submitted_files')) {
                    $files = $request->file('submitted_files');
                    foreach ($files as $fieldId => $file) {
                        $filePath = FileService::compressAndUpload($file, $this->receiptFolder, 'public');
                        // Update the corresponding field in submittedFields
                        foreach ($submittedFields as &$field) {
                            if (($field['fieldId'] ?? $field['field_id'] ?? '') == $fieldId || ($field['field_name'] ?? '') == $fieldId) {
                                $field['value'] = $filePath;
                            }
                        }
                    }
                }
            }

            $deposit = ManualDeposit::create([
                'user_id' => $user->id,
                'manual_deposit_method_id' => $request->method_id,
                'amount' => $request->amount,
                'transaction_id' => $request->transaction_id,
                'receipt' => $receiptPath,
                'submitted_fields' => $submittedFields,
                'status' => 'pending',
            ]);

            // Notify all super-admins about the new manual deposit request
            try {
                $deposit->load('method');
                $admins = User::role(config('constants.SYSTEM_ROLES.SUPER_ADMIN'))->get();
                foreach ($admins as $admin) {
                    $admin->notify(new AdminNewManualDepositNotification($deposit, $user));
                }
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::error('ManualDepositApiController: Failed to notify admins of new manual deposit request', [
                    'deposit_id' => $deposit->id,
                    'user_id'    => $user->id,
                    'error'      => $e->getMessage(),
                ]);
            }

            return ApiResponseService::successResponse('Deposit request submitted successfully. It will be processed after verification.', $deposit);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Failed to submit deposit request: ' . $e->getMessage());
        }
    }

    /**
     * Get user's manual deposit history
     */
    public function getMyDeposits(Request $request)
    {
        $user = Auth::user();
        $perPage = min((int) $request->input('per_page', 15), 50);
        
        $deposits = ManualDeposit::with('method')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return ApiResponseService::successResponse('Manual deposits history retrieved successfully', $deposits);
    }
}
