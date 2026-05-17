<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FaqAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('faqs-list');

        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 15), 100);
        $withTrashed = $request->boolean('with_trashed');

        $query = Faq::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('question', 'like', "%{$search}%")->orWhere('answer', 'like', "%{$search}%");
            }));

        $faqs = $query->orderBy('sequence')->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('FAQs retrieved'), $faqs);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('faqs-list');

        $faq = Faq::withTrashed()->find($id);
        if (!$faq) {
            return $this->jsonError(__('FAQ not found'), 404);
        }

        return $this->jsonSuccess(__('FAQ retrieved'), $faq);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('faqs-create');

        $validator = Validator::make($request->all(), [
            'question' => 'required|string|min:2',
            'answer' => 'required|string|min:2',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            $data = $validator->validated();
            $data['is_active'] = $request->boolean('is_active', true);
            $faq = Faq::create($data);
            DB::commit();
            return $this->jsonSuccess(__('FAQ created successfully'), $faq, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to create FAQ') . ': ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('faqs-edit');

        $faq = Faq::find($id);
        if (!$faq) {
            return $this->jsonError(__('FAQ not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'question' => 'required|string|min:2',
            'answer' => 'required|string|min:2',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $faq->update($validator->validated());
        return $this->jsonSuccess(__('FAQ updated successfully'), $faq->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('faqs-delete');

        $faq = Faq::withTrashed()->find($id);
        if (!$faq) {
            return $this->jsonError(__('FAQ not found'), 404);
        }

        $faq->forceDelete();
        return $this->jsonSuccess(__('FAQ deleted successfully'));
    }

    public function restore(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('faqs-edit');

        $faq = Faq::onlyTrashed()->find($id);
        if (!$faq) {
            return $this->jsonError(__('FAQ not found'), 404);
        }

        $faq->restore();
        return $this->jsonSuccess(__('FAQ restored successfully'), $faq->fresh());
    }

    /**
     * POST /api/admin/faqs/reorder - Update FAQ display order
     */
    public function reorder(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('faqs-edit');

        $validator = Validator::make($request->all(), [
            'order'   => 'required|array',
            'order.*' => 'integer|exists:faqs,id',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            foreach ($request->order as $index => $faqId) {
                Faq::where('id', $faqId)->update(['sequence' => $index + 1]);
            }
            DB::commit();
            return $this->jsonSuccess(__('FAQ order updated successfully'));
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to update FAQ order') . ': ' . $e->getMessage(), 500);
        }
    }
}
