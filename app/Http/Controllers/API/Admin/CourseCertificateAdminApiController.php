<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Course\CourseCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CourseCertificateAdminApiController extends AdminCrudApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('certificates-list');

        $perPage = min((int) $request->input('per_page', 15), 100);
        $search = $request->input('search');

        $query = CourseCertificate::with(['user:id,name,email', 'course:id,title']);

        if ($search) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhereHas('course', function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%");
            })->orWhere('certificate_number', 'like', "%{$search}%");
        }

        $certificates = $query->latest('issued_date')->paginate($perPage);

        return $this->jsonSuccess(__('Certificates retrieved successfully'), $certificates);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('certificates-list');

        $certificate = CourseCertificate::with(['user:id,name,email', 'course:id,title'])->find($id);

        if (!$certificate) {
            return $this->jsonError(__('Certificate not found'), 404);
        }

        return $this->jsonSuccess(__('Certificate retrieved successfully'), $certificate);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('certificates-create');

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'course_id' => 'required|exists:courses,id',
            'issued_date' => 'nullable|date',
            'certificate_number' => 'nullable|string|unique:course_certificates,certificate_number',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        
        // Auto generate if not provided
        if (empty($data['certificate_number'])) {
            $data['certificate_number'] = strtoupper(Str::random(10));
        }

        if (empty($data['issued_date'])) {
            $data['issued_date'] = now();
        }

        $certificate = CourseCertificate::create($data);

        return $this->jsonSuccess(__('Certificate issued successfully'), $certificate->load(['user:id,name,email', 'course:id,title']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('certificates-edit');

        $certificate = CourseCertificate::find($id);

        if (!$certificate) {
            return $this->jsonError(__('Certificate not found'), 404);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'sometimes|exists:users,id',
            'course_id' => 'sometimes|exists:courses,id',
            'issued_date' => 'sometimes|date',
            'certificate_number' => 'sometimes|string|unique:course_certificates,certificate_number,' . $id,
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $certificate->update($validator->validated());

        return $this->jsonSuccess(__('Certificate updated successfully'), $certificate->load(['user:id,name,email', 'course:id,title']));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('certificates-delete');

        $certificate = CourseCertificate::find($id);

        if (!$certificate) {
            return $this->jsonError(__('Certificate not found'), 404);
        }

        $certificate->delete();

        return $this->jsonSuccess(__('Certificate deleted successfully'));
    }
}
