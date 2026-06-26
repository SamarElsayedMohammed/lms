<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Requests\ImportCoursesExcelRequest;
use App\Services\CourseExcelImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CourseExcelImportApiController extends AdminCrudApiController
{
    public function __construct(
        private readonly CourseExcelImportService $courseExcelImportService,
    ) {
        $this->middleware('auth:sanctum');
    }

    public function store(ImportCoursesExcelRequest $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('courses-edit');

        $userId = (int) ($request->input('user_id') ?: Auth::id());
        $categoryId = (int) $request->input('category_id');
        $languageId = $request->filled('language_id') ? (int) $request->input('language_id') : null;

        $path = $request->file('file')->getRealPath();
        if ($path === false) {
            return $this->jsonError(__('Invalid upload'), 422);
        }

        try {
            $stats = $this->courseExcelImportService->import($path, $userId, $categoryId, $languageId);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 422);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return $this->jsonError(__('Import failed: :msg', ['msg' => $e->getMessage()]), 500);
        }

        return $this->jsonSuccess(__('Courses import completed'), $stats);
    }
}
