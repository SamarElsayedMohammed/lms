<?php

namespace App\Http\Controllers;

use App\Models\PromoCode;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PromoCodeController extends Controller
{
    public function index()
    {
        ResponseService::noPermissionThenRedirect('promo-codes-list');
        return view('promo-codes.index', ['type_menu' => 'promo-codes']);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('promo-codes-create');

        $rules = [
            'promo_code' => 'required|string|max:255|unique:promo_codes,promo_code',
            'message' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'no_of_users' => 'nullable|numeric|min:0',
            'discount' => 'required|numeric|min:0',
            'discount_type' => 'required|string|max:255',
        ];

        // For percentage discount type, discount cannot exceed 100%
        if ($request->discount_type === 'percentage') {
            $rules['discount'] = 'required|numeric|min:0|max:100';
        } else {
            // For fixed amount, set reasonable max limit
            $rules['discount'] = 'required|numeric|min:0|max:999999999.99';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            // Check if request is AJAX (jQuery sets X-Requested-With header)
            if (
                $request->ajax()
                || $request->wantsJson()
                || $request->header('X-Requested-With') === 'XMLHttpRequest'
            ) {
                $errors = $validator->errors()->all();
                $firstError = $validator->errors()->first();
                // Get specific error for promo_code if it exists
                if ($validator->errors()->has('promo_code')) {
                    $firstError = $validator->errors()->first('promo_code');
                }
                return ResponseService::validationError($firstError, ['errors' => $errors]);
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }
        try {
            $data = $request->all();
            $data['user_id'] = Auth::id();

            // Set default values for repeat_usage fields
            $data['repeat_usage'] = false;
            $data['no_of_repeat_usage'] = 0;

            $promoCode = PromoCode::create($data);

            ResponseService::successResponse('Promo Code Created Successfully');
        } catch (Exception $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorRedirectResponse('Failed to create Promo Code');
        }
    }

    public function show(Request $request)
    {
        ResponseService::noPermissionThenSendJson('promo-codes-list');

        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'DESC');
        $search = $request->input('search');
        $showDeleted = $request->input('show_deleted');

        $sql = PromoCode::query()
            ->when($showDeleted == 1 || $showDeleted === '1', static function ($query): void {
                $query->onlyTrashed();
            })
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q->where('promo_code', 'LIKE', "%$search%")->orWhere('message', 'LIKE', "%$search%");
                });
            });

        $sql->orderBy($sort, $order);

        $total = $sql->count();
        $result = $sql->skip($offset)->take($limit)->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = $offset + 1;

        foreach ($result as $row) {
            $operate = '';
            if ($showDeleted == 1 || $showDeleted === '1') {
                if (auth()->user()->can('promo-codes-edit')) {
                    $operate .= BootstrapTableService::restoreButton(route('promo-codes.restore', $row->id));
                }
                if (auth()->user()->can('promo-codes-delete')) {
                    $operate .= BootstrapTableService::trashButton(route('promo-codes.trash', $row->id));
                }
            } else {
                if (auth()->user()->can('promo-codes-edit')) {
                    $operate .= BootstrapTableService::editButton(
                        route('promo-codes.update', $row->id),
                        true,
                        '#promoCodeEditModal',
                        $row->id,
                    );
                }
                if (auth()->user()->can('promo-codes-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('promo-codes.destroy', $row->id));
                }
            }

            // Get dates directly from model attributes before toArray() to ensure proper formatting
            $startDate = '';
            $endDate = '';

            if ($row->start_date) {
                try {
                    $startDate = $row->start_date instanceof \Carbon\Carbon
                        ? $row->start_date->format('Y-m-d')
                        : \Carbon\Carbon::parse($row->start_date)->format('Y-m-d');
                } catch (\Exception $e) {
                    $startDate = '';
                }
            }

            if ($row->end_date) {
                try {
                    $endDate = $row->end_date instanceof \Carbon\Carbon
                        ? $row->end_date->format('Y-m-d')
                        : \Carbon\Carbon::parse($row->end_date)->format('Y-m-d');
                } catch (\Exception $e) {
                    $endDate = '';
                }
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['operate'] = $operate;

            // Override dates with properly formatted values (always set, even if empty)
            $tempRow['start_date'] = $startDate;
            $tempRow['end_date'] = $endDate;

            // Check if promo code has expired and set status to disabled (0)
            $isExpired = false;
            if ($row->end_date) {
                try {
                    $endDateObj = $row->end_date instanceof \Carbon\Carbon
                        ? $row->end_date
                        : \Carbon\Carbon::parse($row->end_date);

                    // If end_date has passed, automatically set status to disabled
                    if ($endDateObj->isPast()) {
                        $tempRow['status'] = 0;
                        $tempRow['is_expired'] = true;
                        $isExpired = true;
                    } else {
                        $tempRow['is_expired'] = false;
                    }
                } catch (\Exception $e) {
                    $tempRow['is_expired'] = false;
                }
            } else {
                $tempRow['is_expired'] = false;
            }

            // Add export column for status (Expired/Active/Deactive)
            if ($isExpired) {
                $tempRow['status_export'] = 'Expired';
            } else {
                $tempRow['status_export'] =
                    $tempRow['status'] == 1
                    || $tempRow['status'] === 1
                    || $tempRow['status'] === '1'
                    || $tempRow['status'] === true
                        ? 'Active'
                        : 'Deactive';
            }

            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    // public function edit(Tax $tax)
    // {
    //     return view('taxes.edit', compact('tax'));
    // }

    public function update(Request $request, PromoCode $promoCode)
    {
        ResponseService::noPermissionThenSendJson('promo-codes-edit');

        $rules = [
            'promo_code' => 'required|string|max:255|unique:promo_codes,promo_code,' . $promoCode->id,
            'message' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'no_of_users' => 'nullable|numeric|min:0',
            'discount' => 'required|numeric|min:0',
            'discount_type' => 'required|string|max:255',
        ];

        // For percentage discount type, discount cannot exceed 100%
        if ($request->discount_type === 'percentage') {
            $rules['discount'] = 'required|numeric|min:0|max:100';
        } else {
            // For fixed amount, set reasonable max limit
            $rules['discount'] = 'required|numeric|min:0|max:999999999.99';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $validator->validated();

            // Set default values for repeat_usage fields
            $data['repeat_usage'] = false;
            $data['no_of_repeat_usage'] = 0;

            $promoCode->update($data);

            return ResponseService::successResponse('Promo Code Updated Successfully');
        } catch (Exception $th) {
            ResponseService::logErrorRedirect($th, 'PromoCodeController -> update()');
            return ResponseService::errorResponse();
        }
    }

    public function destroy(PromoCode $promoCode)
    {
        ResponseService::noPermissionThenSendJson('promo-codes-delete');
        $promoCode->delete();
        return ResponseService::successResponse('Promo Code Deleted Successfully');
    }

    /**
     * Restore a soft-deleted promo code
     */
    public function restore($id)
    {
        ResponseService::noPermissionThenSendJson('promo-codes-delete');
        try {
            $promoCode = PromoCode::onlyTrashed()->findOrFail($id);
            $promoCode->restore();
            return ResponseService::successResponse('Promo Code Restored Successfully');
        } catch (Exception $th) {
            ResponseService::logErrorRedirect($th, 'PromoCodeController -> restore');
            return ResponseService::errorResponse('Failed to restore promo code.');
        }
    }

    /**
     * Permanently delete a soft-deleted promo code
     */
    public function trash($id)
    {
        ResponseService::noPermissionThenSendJson('promo-codes-delete');
        try {
            $promoCode = PromoCode::onlyTrashed()->findOrFail($id);
            $promoCode->forceDelete();
            return ResponseService::successResponse('Promo Code Permanently Deleted Successfully');
        } catch (Exception $th) {
            ResponseService::logErrorRedirect($th, 'PromoCodeController -> trash');
            return ResponseService::errorResponse('Failed to permanently delete promo code.');
        }
    }
}
