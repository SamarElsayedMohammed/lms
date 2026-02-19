<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Intl\Countries;
use Throwable;

class TaxController extends Controller
{
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['taxes-list', 'taxes-create', 'taxes-edit', 'taxes-delete']);

        // Get list of countries for dropdown
        $countries = [];
        try {
            $countryCodes = Countries::getCountryCodes();
            foreach ($countryCodes as $code) {
                $countries[$code] = Countries::getName($code, 'en');
            }
            asort($countries); // Sort alphabetically by country name
        } catch (\Exception) {
            // Fallback to common countries if Intl extension is not available
            $countries = [
                'US' => 'United States',
                'GB' => 'United Kingdom',
                'IN' => 'India',
                'CA' => 'Canada',
                'AU' => 'Australia',
                'DE' => 'Germany',
                'FR' => 'France',
                'IT' => 'Italy',
                'ES' => 'Spain',
                'BR' => 'Brazil',
            ];
        }

        // Check if a default tax already exists
        $hasDefaultTax = Tax::where('is_default', true)->exists();

        return view('taxes.index', [
            'type_menu' => 'taxes',
            'countries' => $countries,
            'hasDefaultTax' => $hasDefaultTax,
        ]);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('taxes-create');

        // Handle checkbox - hidden input sends '0', checkbox sends '1' when checked
        $isDefault = $request->is_default == '1' || $request->is_default === 1 || $request->is_default === true;

        // If is_default is checked, ensure country_code is null
        if ($isDefault) {
            // Check if a default tax already exists
            $existingDefaultTax = Tax::where('is_default', true)->first();
            if ($existingDefaultTax) {
                return ResponseService::validationError(
                    'A default tax already exists. Please edit the existing default tax or unset it before creating a new one.',
                );
            }

            $request->merge(['country_code' => null, 'is_default' => true]);
            $request->validate([
                'name' => 'required|string|max:255',
                'percentage' => 'required|numeric|min:0|max:999.99',
                'is_default' => 'nullable|boolean',
            ]);
        } else {
            // If not default, country_code is required
            $request->merge(['is_default' => false]);
            $request->validate([
                'name' => 'required|string|max:255',
                'percentage' => 'required|numeric|min:0|max:999.99',
                'country_code' => 'required|string|size:2',
                'is_default' => 'nullable|boolean',
            ]);
        }
        try {
            $data = $request->only(['name', 'percentage', 'country_code', 'is_default']);
            // Set is_active to true by default
            $data['is_active'] = true;
            $tax = Tax::create($data);

            ResponseService::successResponse('Tax Created Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorRedirectResponse('Failed to create Tax');
        }
    }

    public function show(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('taxes-list');

        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'DESC');
        $search = $request->input('search');
        $showDeleted = $request->input('show_deleted');

        $sql = Tax::query()
            ->when($showDeleted == 1 || $showDeleted === '1', static function ($query): void {
                $query->onlyTrashed();
            })
            ->when(!empty($search), static function ($query) use ($search): void {
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('name', 'LIKE', "%$search%")
                        ->orWhere('percentage', 'LIKE', "%$search%")
                        ->orWhere('country_code', 'LIKE', "%$search%");
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
                if (auth()->user()->can('taxes-edit')) {
                    $operate .= BootstrapTableService::restoreButton(route('taxes.restore', $row->id));
                }
                if (auth()->user()->can('taxes-delete')) {
                    $operate .= BootstrapTableService::trashButton(route('taxes.trash', $row->id));
                }
            } else {
                if (auth()->user()->can('taxes-edit')) {
                    $operate .= BootstrapTableService::editButton(
                        route('taxes.update', $row->id),
                        true,
                        '#taxEditModal',
                        $row->id,
                    );
                }
                if (auth()->user()->can('taxes-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('taxes.destroy', $row->id));
                }
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['operate'] = $operate;

            // Ensure is_active field is always present and properly formatted as integer
            $isActiveValue = $row->getAttribute('is_active');
            if ($isActiveValue === null || $isActiveValue === false || $isActiveValue === 0 || $isActiveValue === '0') {
                $tempRow['is_active'] = 0;
            } elseif ($isActiveValue === true || $isActiveValue === 1 || $isActiveValue === '1') {
                $tempRow['is_active'] = 1;
            } else {
                $tempRow['is_active'] = (int) $isActiveValue;
            }

            // Double-check: if is_active is still not set, default to 0
            if (!isset($tempRow['is_active'])) {
                $tempRow['is_active'] = 0;
            }

            // Add export-ready status field (for CSV export)
            $tempRow['status_export'] = $tempRow['is_active'] == 1 ? 'Active' : 'Deactive';

            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    // public function edit(Tax $tax)
    // {
    //     return view('taxes.edit', compact('tax'));
    // }

    public function update(Request $request, Tax $tax)
    {
        ResponseService::noPermissionThenRedirect('taxes-edit');

        // Handle checkbox - hidden input sends '0', checkbox sends '1' when checked
        $isDefault = $request->is_default == '1' || $request->is_default === 1 || $request->is_default === true;

        // If is_default is checked, ensure country_code is null
        if ($isDefault) {
            $request->merge(['country_code' => null, 'is_default' => true]);
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'percentage' => 'required|numeric|min:1|max:99.99',
                'is_default' => 'nullable|boolean',
            ]);

            // Ensure only one default tax exists (unset other default taxes)
            Tax::where('is_default', true)->where('id', '!=', $tax->id)->update(['is_default' => false]);
        } else {
            // If not default, country_code is required
            $request->merge(['is_default' => false]);
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'percentage' => 'required|numeric|min:1|max:99.99',
                'country_code' => 'required|string|size:2',
                'is_default' => 'nullable|boolean',
            ]);
        }

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $validator->validated();
            // is_active is not updated from edit form - keep existing value
            // Ensure is_default is set correctly
            $data['is_default'] = $isDefault;
            if ($data['is_default']) {
                $data['country_code'] = null;
            }

            $tax->update($data);

            return ResponseService::successResponse('Tax Updated Successfully');
        } catch (Exception $th) {
            ResponseService::logErrorRedirect($th, 'TaxController -> update()');
            return ResponseService::errorResponse();
        }
    }

    public function destroy(Tax $tax)
    {
        $tax->delete();
        return ResponseService::successResponse('Tax Deleted Successfully');
    }

    /**
     * Restore a soft-deleted tax
     */
    public function restore($id)
    {
        try {
            $tax = Tax::onlyTrashed()->findOrFail($id);
            $tax->restore();
            return ResponseService::successResponse('Tax Restored Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'TaxController -> restore');
            return ResponseService::errorResponse('Failed to restore tax.');
        }
    }

    /**
     * Permanently delete a soft-deleted tax
     */
    public function trash($id)
    {
        try {
            $tax = Tax::onlyTrashed()->findOrFail($id);
            $tax->forceDelete();
            return ResponseService::successResponse('Tax Permanently Deleted Successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'TaxController -> trash');
            return ResponseService::errorResponse('Failed to permanently delete tax.');
        }
    }
}
