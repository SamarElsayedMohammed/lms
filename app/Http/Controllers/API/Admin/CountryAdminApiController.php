<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CountryAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('countries-list');

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $status = $request->input('status'); // 1=active, 0=inactive, null=all

        $query = Country::query()
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name_en', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%")
                    ->orWhere('currency_name', 'like', "%{$search}%");
            }))
            ->when($status !== null, fn ($q) => $q->where('status', (bool) $status));

        $countries = $query->orderBy('id')->paginate($perPage);

        return $this->jsonSuccess(__('Countries retrieved'), $countries);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('countries-list');

        $country = Country::find($id);
        if (!$country) {
            return $this->jsonError(__('Country not found'), 404);
        }

        return $this->jsonSuccess(__('Country retrieved'), $country);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('countries-create');

        $validator = Validator::make($request->all(), [
            'name_en' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'currency_name' => 'nullable|string|max:255',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $data['status'] = $request->boolean('status', true);
        $country = Country::create($data);

        return $this->jsonSuccess(__('Country created successfully'), $country, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('countries-edit');

        $country = Country::find($id);
        if (!$country) {
            return $this->jsonError(__('Country not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'name_en' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'currency_name' => 'nullable|string|max:255',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $country->update($validator->validated());
        return $this->jsonSuccess(__('Country updated successfully'), $country->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('countries-delete');

        $country = Country::find($id);
        if (!$country) {
            return $this->jsonError(__('Country not found'), 404);
        }

        $country->delete();
        return $this->jsonSuccess(__('Country deleted successfully'));
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('countries-toggle');

        $country = Country::find($id);
        if (!$country) {
            return $this->jsonError(__('Country not found'), 404);
        }

        $country->status = !$country->status;
        $country->save();

        return $this->jsonSuccess(__('Country status updated'), ['status' => (bool) $country->status]);
    }
}
