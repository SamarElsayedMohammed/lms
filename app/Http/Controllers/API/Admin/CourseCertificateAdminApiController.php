<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Course\CourseCertificate;
use App\Services\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CourseCertificateAdminApiController extends AdminCrudApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('certificates-list');

        $perPage = min((int) $request->input('per_page', 15), 100);
        $search = $request->input('search');
        $userId = $request->input('user_id');
        $courseId = $request->input('course_id');
        $status = $request->input('status');

        $query = CourseCertificate::with(['user:id,name,email', 'course:id,title']);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        if ($status && in_array($status, ['active', 'revoked'], true)) {
            $query->where('status', $status);
        }

        if ($search) {
            $normalizedSearch = CourseCertificate::normalizeCertificateNumber($search);
            $query->where(function ($q) use ($search, $normalizedSearch) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('course', function ($cq) use ($search) {
                    $cq->where('title', 'like', "%{$search}%");
                })->orWhere('certificate_number', 'like', "%{$search}%")
                  ->orWhere('certificate_number', 'like', "%{$normalizedSearch}%")
                  ->orWhere('verification_token', 'like', "%{$search}%");
            });
        }

        $certificates = $query->latest('issued_date')->paginate($perPage);

        // Calculate global metrics
        $totalCertificates = CourseCertificate::count();
        $issuedThisMonth = CourseCertificate::whereYear('issued_date', now()->year)
            ->whereMonth('issued_date', now()->month)
            ->count();
        $activeCertificates = CourseCertificate::where('status', 'active')->count();
        $revokedCertificates = CourseCertificate::where('status', 'revoked')->count();

        return response()->json([
            'success' => true,
            'message' => __('Certificates retrieved successfully'),
            'data'    => $certificates,
            'meta'    => [
                'total_certificates'   => $totalCertificates,
                'issued_this_month'    => $issuedThisMonth,
                'active_certificates'  => $activeCertificates,
                'revoked_certificates' => $revokedCertificates,
            ],
        ]);
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
            'user_id'          => 'required|exists:users,id',
            'course_id'        => 'required|exists:courses,id',
            'issued_date'      => 'nullable|date',
            'allow_incomplete' => 'nullable|boolean',
            'reason'           => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $certificate = app(CertificateService::class)->issueCertificate(
            (int) $data['user_id'],
            (int) $data['course_id'],
            [
                'issuance_source'  => 'admin_manual',
                'issuer_id'        => Auth::id(),
                'issued_date'      => $data['issued_date'] ?? now()->toDateString(),
                'allow_incomplete' => (bool) ($data['allow_incomplete'] ?? true),
                'reason'           => $data['reason'] ?? 'Issued by administrator',
            ]
        );

        if (!$certificate) {
            return $this->jsonError(__('Failed to issue certificate. Please verify student enrollment or course eligibility.'), 400);
        }

        return $this->jsonSuccess(
            __('Certificate issued successfully'),
            $certificate->load(['user:id,name,email', 'course:id,title']),
            201
        );
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
            'user_id'            => 'sometimes|exists:users,id',
            'course_id'          => 'sometimes|exists:courses,id',
            'issued_date'        => 'sometimes|date',
            'student_name'       => 'sometimes|string|max:255',
            'arabic_title'       => 'sometimes|string|max:255',
            'english_title'      => 'sometimes|string|max:255',
            'instructor_name'    => 'sometimes|string|max:255',
            'status'             => 'sometimes|in:active,revoked',
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
