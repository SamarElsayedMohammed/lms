<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PromoCodeAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('promo-codes-list');

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $withTrashed = $request->boolean('with_trashed');

        $query = PromoCode::with(['creator', 'subscriptionPlans'])
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('promo_code', 'like', "%{$search}%")->orWhere('message', 'like', "%{$search}%");
            }));

        $promoCodes = $query->orderBy('id', 'desc')->paginate($perPage);

        // ── Stats (calculated on full dataset, ignoring current filters) ──
        $stats = PromoCode::withTrashed()->selectRaw('
            COUNT(CASE WHEN deleted_at IS NULL THEN 1 END)              as total,
            COUNT(CASE WHEN deleted_at IS NULL AND status = 1 THEN 1 END) as active,
            COUNT(CASE WHEN deleted_at IS NULL AND status = 0 THEN 1 END) as inactive,
            COUNT(CASE WHEN deleted_at IS NOT NULL THEN 1 END)          as trashed
        ')->first();

        $data = $promoCodes->toArray();
        $data['stats'] = [
            'total'    => (int) $stats->total,
            'active'   => (int) $stats->active,
            'inactive' => (int) $stats->inactive,
            'trashed'  => (int) $stats->trashed,
        ];

        return $this->jsonSuccess(__('Promo codes retrieved'), $data);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('promo-codes-list');

        $promoCode = PromoCode::with(['creator', 'subscriptionPlans'])->withTrashed()->find($id);
        if (!$promoCode) {
            return $this->jsonError(__('Promo code not found'), 404);
        }

        return $this->jsonSuccess(__('Promo code retrieved'), $promoCode);
    }

    public function trashed(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('promo-codes-list');

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = PromoCode::with(['creator', 'subscriptionPlans'])
            ->onlyTrashed()
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('promo_code', 'like', "%{$search}%")->orWhere('message', 'like', "%{$search}%");
            }));

        $promoCodes = $query->orderBy('deleted_at', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Trashed promo codes retrieved'), $promoCodes);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('promo-codes-create');

        $rules = [
            'promo_code' => 'required|string|max:255|unique:promo_codes,promo_code',
            'message' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'no_of_users' => 'nullable|integer|min:0',
            'repeat_usage' => 'boolean',
            'no_of_repeat_usage' => 'nullable|integer|min:0',
            'discount' => 'required|numeric|min:0',
            'discount_type' => 'required|in:percentage,fixed,amount',
            'subscription_plan_ids' => 'nullable|array',
            'subscription_plan_ids.*' => 'exists:subscription_plans,id',
            'plan_ids' => 'nullable|array',
            'plan_ids.*' => 'exists:subscription_plans,id',
        ];
        if ($request->discount_type === 'percentage') {
            $rules['discount'] = 'required|numeric|min:0|max:100';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        if (($data['discount_type'] ?? null) === 'fixed') {
            $data['discount_type'] = 'amount';
        }
        $data['user_id'] = Auth::id();
        $data['repeat_usage'] = $data['repeat_usage'] ?? false;
        $data['no_of_repeat_usage'] = $data['no_of_repeat_usage'] ?? 0;
        $data['status'] = $request->boolean('status', true);
        $planIds = $data['subscription_plan_ids'] ?? $data['plan_ids'] ?? [];
        unset($data['subscription_plan_ids'], $data['plan_ids']);

        $promoCode = PromoCode::create($data);
        if (!empty($planIds)) {
            $promoCode->subscriptionPlans()->sync($planIds);
        }

        return $this->jsonSuccess(__('Promo code created successfully'), $promoCode->load('subscriptionPlans'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('promo-codes-edit');

        $promoCode = PromoCode::find($id);
        if (!$promoCode) {
            return $this->jsonError(__('Promo code not found'), 404);
        }

        $rules = [
            'promo_code' => 'required|string|max:255|unique:promo_codes,promo_code,' . $id,
            'message' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'no_of_users' => 'nullable|integer|min:0',
            'repeat_usage' => 'boolean',
            'no_of_repeat_usage' => 'nullable|integer|min:0',
            'discount' => 'required|numeric|min:0',
            'discount_type' => 'required|in:percentage,fixed,amount',
            'subscription_plan_ids' => 'nullable|array',
            'subscription_plan_ids.*' => 'exists:subscription_plans,id',
            'plan_ids' => 'nullable|array',
            'plan_ids.*' => 'exists:subscription_plans,id',
        ];
        if ($request->discount_type === 'percentage') {
            $rules['discount'] = 'required|numeric|min:0|max:100';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        if (($data['discount_type'] ?? null) === 'fixed') {
            $data['discount_type'] = 'amount';
        }
        $data['repeat_usage'] = $data['repeat_usage'] ?? false;
        $data['no_of_repeat_usage'] = $data['no_of_repeat_usage'] ?? 0;
        
        $planIds = $data['subscription_plan_ids'] ?? $data['plan_ids'] ?? [];
        unset($data['subscription_plan_ids'], $data['plan_ids']);
        $data['status'] = $request->boolean('status', $promoCode->status);

        $promoCode->update($data);
        $promoCode->subscriptionPlans()->sync($planIds);

        return $this->jsonSuccess(__('Promo code updated successfully'), $promoCode->fresh(['subscriptionPlans']));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('promo-codes-delete');

        $promoCode = PromoCode::find($id);
        if (!$promoCode) {
            return $this->jsonError(__('Promo code not found'), 404);
        }

        $promoCode->delete();
        return $this->jsonSuccess(__('Promo code deleted successfully'));
    }

    public function restore(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('promo-codes-edit');

        $promoCode = PromoCode::onlyTrashed()->find($id);
        if (!$promoCode) {
            return $this->jsonError(__('Promo code not found'), 404);
        }

        $promoCode->restore();
        return $this->jsonSuccess(__('Promo code restored successfully'), $promoCode->fresh());
    }
}
