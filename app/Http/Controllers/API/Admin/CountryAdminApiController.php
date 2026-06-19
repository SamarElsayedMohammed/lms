<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Requests\Admin\StoreCountryRequest;
use App\Http\Requests\Admin\UpdateCountryRequest;
use App\Http\Resources\Admin\CountryAdminResource;
use App\Models\Country;
use App\Models\SupportedCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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

        // ── معالجة مشكلة الفرونت إند: لو الـ currency_code مبعوت null ──
        if (empty($data['currency_code'])) {
            if (!empty($data['currency_name']) && strlen(trim($data['currency_name'])) === 3) {
                $data['currency_code'] = strtoupper(trim($data['currency_name']));
            } elseif (!empty($data['iso_code']) && strlen(trim($data['iso_code'])) >= 3) {
                $data['currency_code'] = strtoupper(substr(trim($data['iso_code']), 0, 3));
            }
        }

        $data['status'] = $request->boolean('status', true);
        $country = Country::create($data);

        // أوتوماتيك: لو الدولة عندها عملة، ضيفها في supported_currencies واجيب سعرها من API
        if (!empty($country->currency_code)) {
            $this->autoCreateCurrencyForCountry($country);
        }

        return $this->jsonSuccess(
            __('Country created successfully'),
            new CountryAdminResource($country),
            201,
        );
    }

    /**
     * إذا الدولة ملهاش عملة في supported_currencies، يضيفها أوتوماتيك ويجيب سعر الصرف من API.
     */
    private function autoCreateCurrencyForCountry(Country $country): void
    {
        $countryCode = strtoupper(substr($country->iso_code, 0, 2));
        $currencyCode = strtoupper($country->currency_code);

        $alreadyExists = SupportedCurrency::where('country_code', $countryCode)->exists();
        if ($alreadyExists) {
            return; // موجودة أصلاً، مش محتاجين نضيفها
        }

        SupportedCurrency::create([
            'country_code'        => $countryCode,
            'country_name'        => $country->name_en ?? $country->name_ar ?? $countryCode,
            'currency_code'       => $currencyCode,
            'currency_symbol'     => $currencyCode,
            'exchange_rate_to_egp' => 1, // مؤقت، هيتحدث أوتوماتيك من الـ Job
            'use_manual_rate'     => false, // default: أوتوماتيك دايماً
            'is_active'           => true,
        ]);

        // نادي على الـ Job عشان يجيب السعر الحقيقي من exchangerate-api.com
        try {
            \App\Jobs\UpdateExchangeRatesJob::dispatch();
            Log::info("Currency auto-created for country [{$countryCode}] and rate-update job dispatched.");
        } catch (\Exception $e) {
            Log::error('Failed to dispatch UpdateExchangeRatesJob after country creation: ' . $e->getMessage());
        }
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

        // ── معالجة مشكلة الفرونت إند: لو الـ currency_code مبعوت null ──
        if (empty($data['currency_code']) && empty($country->currency_code)) {
            if (!empty($data['currency_name']) && strlen(trim($data['currency_name'])) === 3) {
                $data['currency_code'] = strtoupper(trim($data['currency_name']));
            } elseif (!empty($data['iso_code']) && strlen(trim($data['iso_code'])) >= 3) {
                $data['currency_code'] = strtoupper(substr(trim($data['iso_code']), 0, 3));
            }
        }

        if ($request->has('status')) {
            $data['status'] = $request->boolean('status');
        }

        $country->update($data);

        if (!empty($country->currency_code)) {
            $this->autoCreateCurrencyForCountry($country);
        }

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
