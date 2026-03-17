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

        $query = PromoCode::with(['creator', 'courses'])
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('promo_code', 'like', "%{$search}%")->orWhere('message', 'like', "%{$search}%");
            }));

        $promoCodes = $query->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Promo codes retrieved'), $promoCodes);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('promo-codes-list');

        $promoCode = PromoCode::with(['creator', 'courses'])->withTrashed()->find($id);
        if (!$promoCode) {
            return $this->jsonError(__('Promo code not found'), 404);
        }

        return $this->jsonSuccess(__('Promo code retrieved'), $promoCode);
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
            'discount' => 'required|numeric|min:0',
            'discount_type' => 'required|in:percentage,fixed',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'exists:courses,id',
        ];
        if ($request->discount_type === 'percentage') {
            $rules['discount'] = 'required|numeric|min:0|max:100';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $data['user_id'] = Auth::id();
        $data['repeat_usage'] = $request->boolean('repeat_usage', false);
        $data['no_of_repeat_usage'] = $request->input('no_of_repeat_usage', 0);
        $data['status'] = $request->boolean('status', true);
        $courseIds = $data['course_ids'] ?? [];
        unset($data['course_ids']);

        $promoCode = PromoCode::create($data);
        if (!empty($courseIds)) {
            $promoCode->courses()->sync($courseIds);
        }

        return $this->jsonSuccess(__('Promo code created successfully'), $promoCode->load('courses'), 201);
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
            'discount' => 'required|numeric|min:0',
            'discount_type' => 'required|in:percentage,fixed',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'exists:courses,id',
        ];
        if ($request->discount_type === 'percentage') {
            $rules['discount'] = 'required|numeric|min:0|max:100';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $courseIds = $data['course_ids'] ?? [];
        unset($data['course_ids']);
        $data['status'] = $request->boolean('status', $promoCode->status);

        $promoCode->update($data);
        $promoCode->courses()->sync($courseIds);

        return $this->jsonSuccess(__('Promo code updated successfully'), $promoCode->fresh(['courses']));
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
