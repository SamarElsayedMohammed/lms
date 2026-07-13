<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Certificate;
use App\Models\Course\CourseCertificate;
use App\Models\OrderCourse;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

/**
 * Admin Certificate Management
 *
 * Allows admins to:
 * - Download a PDF of any student certificate resolved by enrollment_id
 * - Revoke a certificate (disable it without deleting)
 * - Restore a revoked certificate
 */
class AdminCertificateController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * GET /api/admin/enrollments/{enrollmentId}/certificate/download
     *
     * Admin-only: Download a student's certificate by enrollment_id.
     * Enrollment = OrderCourse record (a row in order_courses table).
     */
    public function downloadByEnrollment(int $enrollmentId)
    {
        $this->ensureAdmin();

        // Resolve enrollment → student + course
        $enrollment = OrderCourse::with(['order.user', 'course'])->find($enrollmentId);

        if (!$enrollment) {
            return ApiResponseService::errorResponse('Enrollment not found.', null, 404);
        }

        $student = $enrollment->order?->user;
        $course  = $enrollment->course;

        if (!$student || !$course) {
            return ApiResponseService::errorResponse('Enrollment data is incomplete.', null, 422);
        }

        // Ensure the enrollment belongs to a completed order
        if ($enrollment->order?->status !== 'completed') {
            return ApiResponseService::errorResponse(
                'This enrollment is not for a completed order.',
                null,
                422,
            );
        }

        // Find the certificate — by student + course (NOT by the admin's own data)
        $certificate = CourseCertificate::with(['user', 'course'])
            ->where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->first();

        if (!$certificate) {
            return ApiResponseService::errorResponse(
                "No certificate has been generated yet for student \"{$student->name}\" in course \"{$course->title}\".",
                null,
                404,
            );
        }

        if ($certificate->isRevoked()) {
            return ApiResponseService::errorResponse(
                'This certificate has been revoked and cannot be downloaded.',
                null,
                403,
            );
        }

        // Generate PDF using the active template
        $template = Certificate::where('type', 'course_completion')
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();

        if (!$template) {
            return ApiResponseService::errorResponse('No active certificate template found.');
        }

        $html = app(\App\Http\Controllers\CertificateController::class)
            ->generateCertificateHtmlPublic($template, $certificate);

        $settings  = is_string($template->template_settings)
            ? json_decode($template->template_settings, true)
            : $template->template_settings;

        $widthPx  = $settings['width']  ?? 800;
        $heightPx = $settings['height'] ?? 600;
        $widthMM  = round($widthPx  * 0.264583, 2);
        $heightMM = round($heightPx * 0.264583, 2);

        $mpdf = new Mpdf([
            'mode'             => 'utf-8',
            'format'           => [$widthMM, $heightMM],
            'margin_left'      => 0,
            'margin_right'     => 0,
            'margin_top'       => 0,
            'margin_bottom'    => 0,
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
        ]);

        $mpdf->WriteHTML($html);

        $filename = 'certificate-' . $certificate->certificate_number . '.pdf';

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * POST /api/admin/certificates/{id}/revoke
     *
     * Admin-only: Revoke a certificate so it can no longer be downloaded.
     */
    public function revoke(Request $request, int $id)
    {
        $this->ensureAdmin();

        $certificate = CourseCertificate::find($id);
        if (!$certificate) {
            return response()->json([
                'ok' => false,
                'message' => 'Certificate not found.'
            ], 404);
        }

        if ($certificate->isRevoked()) {
            return response()->json([
                'ok' => false,
                'message' => 'Certificate is already revoked.'
            ], 409);
        }

        $certificate->update([
            'status'         => 'revoked',
            'revoked_at'     => now(),
            'revoked_reason' => $request->input('reason', 'Revoked by admin'),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Certificate revoked successfully.',
            'data' => [
                'certificate_number' => $certificate->certificate_number,
                'revoked_at'         => $certificate->revoked_at->toDateTimeString(),
            ]
        ], 200);
    }

    /**
     * POST /api/admin/certificates/{id}/restore
     *
     * Admin-only: Restore a revoked certificate.
     */
    public function restore(int $id)
    {
        $this->ensureAdmin();

        $certificate = CourseCertificate::find($id);
        if (!$certificate) {
            return response()->json([
                'ok' => false,
                'message' => 'Certificate not found.'
            ], 404);
        }

        if ($certificate->isActive()) {
            return response()->json([
                'ok' => false,
                'message' => 'Certificate is already active.'
            ], 409);
        }

        $certificate->update([
            'status'         => 'active',
            'revoked_at'     => null,
            'revoked_reason' => null,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Certificate restored successfully.',
            'data' => [
                'certificate_number' => $certificate->certificate_number,
            ]
        ], 200);
    }
}
