<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class CountryController extends Controller
{
    public function index(Request $request)
    {
        // Require at least list permission
        ResponseService::noAnyPermissionThenRedirect(['countries-list', 'manage_countries']);

        if ($request->ajax() || $request->wantsJson()) {
            return $this->getTableData($request);
        }

        return view('admin.countries.index', [
            'type_menu' => 'countries',
        ]);
    }

    private function getTableData(Request $request): \Illuminate\Http\JsonResponse
    {
        ResponseService::noPermissionThenSendJson('countries-list');

        $offset = (int)$request->input('offset', 0);
        $limit = (int)$request->input('limit', 10);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'DESC');
        $search = $request->input('search');

        $query = Country::query()
            ->when($search, function ($q) use ($search) {
            $q->where('name_en', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%");
        });

        $query->orderBy($sort, strtoupper($order));
        $total = $query->count();
        $result = $query->skip($offset)->take($limit)->get();

        $rows = [];
        $no = $offset + 1;

        foreach ($result as $row) {
            $operate = '';

            if (auth()->user()->can('countries-edit')) {
                // Return data-attributes to open edit modal
                $operate .= '<a href="javascript:void(0)" class="btn btn-icon btn-primary btn-sm mx-1 edit-country-btn" data-id="' . $row->id . '" data-name-en="' . htmlspecialchars($row->name_en) . '" data-name-ar="' . htmlspecialchars($row->name_ar) . '" title="' . __('Edit') . '"><i class="fa fa-edit"></i></a>';
            }
            if (auth()->user()->can('countries-toggle')) {
                $operate .= BootstrapTableService::button(
                    $row->status ? 'fa fa-toggle-on' : 'fa fa-toggle-off',
                    route('countries.toggle', $row->id),
                ['btn-secondary', 'toggle-status'],
                [
                    'title' => $row->status ? __('Deactivate') : __('Activate'),
                    'data-id' => $row->id,
                    'data-active' => $row->status ? '1' : '0',
                ]
                );
            }
            if (auth()->user()->can('countries-delete')) {
                $operate .= BootstrapTableService::deleteButton(route('countries.destroy', $row->id));
            }

            $rows[] = [
                'id' => $row->id,
                'no' => $no++,
                'name_en' => htmlspecialchars($row->name_en),
                'name_ar' => htmlspecialchars($row->name_ar),
                'status' => (int)$row->status,
                'status_display' => $row->status ? __('Active') : __('Inactive'),
                'operate' => $operate,
            ];
        }

        return response()->json(['total' => $total, 'rows' => $rows]);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenSendJson('countries-create');

        $rules = [
            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'status' => 'nullable|boolean',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $validator->validated();
            $data['status'] = $request->boolean('status', true);

            Country::create($data);

            return ResponseService::successResponse(__('Country created successfully'));
        }
        catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function update(Request $request, Country $country)
    {
        ResponseService::noPermissionThenSendJson('countries-edit');

        $rules = [
            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'status' => 'nullable|boolean',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $validator->validated();

            // If status is passed, update it, otherwise keep old
            if ($request->has('status')) {
                $data['status'] = $request->boolean('status');
            }

            $country->update($data);

            return ResponseService::successResponse(__('Country updated successfully'));
        }
        catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function destroy(Country $country)
    {
        ResponseService::noPermissionThenSendJson('countries-delete');

        try {
            $country->delete();
            return ResponseService::successResponse(__('Country deleted successfully'));
        }
        catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }

    public function toggleStatus(int $id)
    {
        ResponseService::noPermissionThenSendJson('countries-toggle');

        try {
            $country = Country::findOrFail($id);
            $country->status = !$country->status;
            $country->save();
            return ResponseService::successResponse(
                $country->status ? __('Country activated') : __('Country deactivated'),
            ['status' => $country->status]
            );
        }
        catch (Exception $e) {
            return ResponseService::errorResponse($e->getMessage());
        }
    }
}