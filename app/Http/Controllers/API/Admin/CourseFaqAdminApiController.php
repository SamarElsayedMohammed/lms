<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\CourseFaq;
use App\Models\Course\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Course FAQ Admin API Controller
 *
 * Manages per-course FAQs: CRUD + reorder
 * Also used by instructors for their own courses.
 *
 * Routes prefix: /api/admin/courses/{courseId}/faqs
 */
class CourseFaqAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET /api/admin/courses/{courseId}/faqs
     */
    public function index(Request $request, int $courseId): JsonResponse
    {
        $this->ensureAdmin();

        $course = Course::find($courseId);
        if (! $course) {
            return $this->jsonError('Course not found', 404);
        }

        $withInactive = $request->boolean('with_inactive', false);

        $faqs = CourseFaq::where('course_id', $courseId)
            ->when(! $withInactive, fn ($q) => $q->active())
            ->ordered()
            ->get();

        return $this->jsonSuccess('Course FAQs retrieved', $faqs);
    }

    /**
     * POST /api/admin/courses/{courseId}/faqs
     */
    public function store(Request $request, int $courseId): JsonResponse
    {
        $this->ensureAdmin();

        $course = Course::find($courseId);
        if (! $course) {
            return $this->jsonError('Course not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'question'  => 'required|string|min:2',
            'answer'    => 'required|string|min:2',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            $maxSeq = CourseFaq::where('course_id', $courseId)->max('sequence') ?? 0;

            $faq = CourseFaq::create([
                'course_id' => $courseId,
                'question'  => $request->question,
                'answer'    => $request->answer,
                'is_active' => $request->boolean('is_active', true),
                'sequence'  => $maxSeq + 1,
            ]);
            DB::commit();

            return $this->jsonSuccess('Course FAQ created successfully', $faq, 201);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError('Failed to create FAQ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/courses/{courseId}/faqs/{id}
     */
    public function show(int $courseId, int $id): JsonResponse
    {
        $this->ensureAdmin();

        $faq = CourseFaq::where('course_id', $courseId)->find($id);
        if (! $faq) {
            return $this->jsonError('FAQ not found', 404);
        }

        return $this->jsonSuccess('Course FAQ retrieved', $faq);
    }

    /**
     * PUT /api/admin/courses/{courseId}/faqs/{id}
     */
    public function update(Request $request, int $courseId, int $id): JsonResponse
    {
        $this->ensureAdmin();

        $faq = CourseFaq::where('course_id', $courseId)->find($id);
        if (! $faq) {
            return $this->jsonError('FAQ not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'question'  => 'sometimes|required|string|min:2',
            'answer'    => 'sometimes|required|string|min:2',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $faq->update($validator->validated());

        return $this->jsonSuccess('Course FAQ updated successfully', $faq->fresh());
    }

    /**
     * DELETE /api/admin/courses/{courseId}/faqs/{id}
     */
    public function destroy(int $courseId, int $id): JsonResponse
    {
        $this->ensureAdmin();

        $faq = CourseFaq::where('course_id', $courseId)->find($id);
        if (! $faq) {
            return $this->jsonError('FAQ not found', 404);
        }

        $faq->delete();

        return $this->jsonSuccess('Course FAQ deleted successfully');
    }

    /**
     * POST /api/admin/courses/{courseId}/faqs/reorder
     * Body: { "order": [3, 1, 2] }   — ordered array of FAQ IDs
     */
    public function reorder(Request $request, int $courseId): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'order'   => 'required|array',
            'order.*' => 'integer|exists:course_faqs,id',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            foreach ($request->order as $index => $faqId) {
                CourseFaq::where('id', $faqId)
                    ->where('course_id', $courseId)
                    ->update(['sequence' => $index + 1]);
            }
            DB::commit();

            return $this->jsonSuccess('Course FAQ order updated successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError('Failed to update order: ' . $e->getMessage(), 500);
        }
    }
}
