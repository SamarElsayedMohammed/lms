<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseCertificate;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;

class CertificateApiController extends Controller
{
    /**
     * Public endpoint to verify a certificate by code
     */
    public function verifyPublic(Request $request)
    {
        $code = $request->query('code');

        if (!$code) {
            return ApiResponseService::validationError('The code query parameter is required.');
        }

        $certificate = CourseCertificate::with(['user:id,name', 'course:id,title'])
            ->where('certificate_number', $code)
            ->first();

        if (!$certificate) {
            return ApiResponseService::errorResponse('Certificate not found or invalid.', [], 404);
        }

        return ApiResponseService::successResponse('Certificate verified successfully.', [
            'valid' => true,
            'certificate' => [
                'code' => $certificate->certificate_number,
                'issue_date' => $certificate->issued_date ? \Carbon\Carbon::parse($certificate->issued_date)->format('Y-m-d') : null,
                'user' => [
                    'name' => $certificate->user ? $certificate->user->name : 'Unknown',
                ],
                'course' => [
                    'title' => $certificate->course ? $certificate->course->title : 'Unknown',
                ],
            ]
        ]);
    }
}
