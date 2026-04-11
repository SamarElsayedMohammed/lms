<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Requests\Admin\StoreCountryRequest;
use App\Http\Requests\Admin\UpdateCountryRequest;
use App\Http\Resources\Admin\CountryAdminResource;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CountryAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->assertCanListCountries();

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
        $this->assertCanListCountries();

        $country = Country::find($id);
        if (!$country) {
            return $this->jsonError(__('Country not found'), 404);
        }

        return $this->jsonSuccess(__('Country retrieved'), new CountryAdminResource($country));
    }

    public function store(StoreCountryRequest $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('countries-create');

        $data = $request->validated();
        $data['status'] = $request->boolean('status', true);
        $country = Country::create($data);

        return $this->jsonSuccess(
            __('Country created successfully'),
            new CountryAdminResource($country),
            201,
        );
    }

    public function update(UpdateCountryRequest $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('countries-edit');

        $country = Country::find($id);
        if (!$country) {
            return $this->jsonError(__('Country not found'), 404);
        }

        $data = $request->validated();
        if ($request->has('status')) {
            $data['status'] = $request->boolean('status');
        }

        $country->update($data);

        return $this->jsonSuccess(__('Country updated successfully'), new CountryAdminResource($country->fresh()));
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

    /**
     * countries-list, or plan-related roles (e.g. Supervisor manage_plans) that need country data.
     */
    private function assertCanListCountries(): void
    {
        $user = Auth::user();
        if ($user === null) {
            $this->unauthorized('Unauthenticated');
        }
        if ($user->can('countries-list')) {
            return;
        }
        if ($user->can('manage_plans')) {
            return;
        }
        foreach (['subscription-plans-list', 'subscription-plans-create', 'subscription-plans-edit'] as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }
        $this->unauthorized(__('You do not have permission to perform this action'));
    }
}
