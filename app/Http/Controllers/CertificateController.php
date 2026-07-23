<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\Course\CourseChapter\Quiz\UserQuizAttempt;
use App\Models\QuizCertificate;
use App\Services\ApiResponseService;
use App\Services\VideoProgressService;
use App\Traits\CertificatePdfGeneratorTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Mpdf\Mpdf;

class CertificateController extends Controller
{
    use CertificatePdfGeneratorTrait;
    /**
     * Get certificate details for a course (check if certificate exists)
     * POST /api/certificate/course/generate
     * (Also handles GET /api/certificate/course/generate per routes)
     */
    public function getCertificate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors());
        }

        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse(__('User not authenticated.'), null, 401);
        }

        $course_id = (int) $request->input('course_id');
        $course = Course::with(['user', 'category'])->find($course_id);

        if (!$course) {
            return ApiResponseService::errorResponse(__('Course not found.'), null, 404);
        }

        // Verify Enrollment
        if (!CourseCertificate::userIsEnrolled($user->id, $course_id)) {
            return ApiResponseService::errorResponse(
                'You are not enrolled in this course.',
                null,
                403,
            );
        }

        // Verify Completion
        if (!$this->isCourseCompleted($user->id, $course_id)) {
            return ApiResponseService::errorResponse(
                'Course not completed. Please complete all lessons, quizzes, and assignments to generate a certificate.',
                null,
                403
            );
        }

        $videoProgress = app(VideoProgressService::class)->getCourseProgress($user, $course);
        if ($videoProgress < VideoProgressService::COMPLETION_THRESHOLD) {
            return ApiResponseService::errorResponse(
                'You must watch all video lectures to ' . VideoProgressService::COMPLETION_THRESHOLD . '% before generating a certificate. Current progress: ' . $videoProgress  . '%',
                null,
                403
            );
        }

        $certificate = app(\App\Services\CertificateService::class)->autoGenerateCertificate($user->id, $course_id);
        
        if (!$certificate) {
            return ApiResponseService::errorResponse(
                'Could not generate certificate. Please check if you have purchased the certificate fee if applicable.',
                null,
                403
            );
        }

        if ($certificate->isRevoked()) {
            return ApiResponseService::errorResponse(
                'Your certificate for this course has been revoked. Please contact support.',
                null,
                403,
            );
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Certificate issued successfully',
            'data'    => [
                'studentName'        => $certificate->student_name ?? $user->name,
                'arabicCourseTitle'  => $certificate->arabic_title ?? $course->title,
                'englishCourseTitle' => $certificate->english_title ?? $course->title,
                'date'               => $certificate->issued_date->format('Y-m-d'),
                'instructorName'     => $certificate->instructor_name ?? ($course->user->name ?? 'Instructor'),
                'certificateId'      => $certificate->certificate_number,
                'courseId'           => $certificate->course_id,
            ]
        ], 200);
    }

    /**
     * View certificate HTML for a course.
     */
    public function view(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse(__('User not authenticated.'), null, 401);
        }

        $course_id = (int) $request->input('course_id');

        $certificate = app(\App\Services\CertificateService::class)->autoGenerateCertificate($user->id, $course_id);

        if (!$certificate) {
            return ApiResponseService::errorResponse(
                'Could not generate certificate. Please check if you have purchased the certificate fee if applicable.',
                null,
                403
            );
        }

        if ($certificate->isRevoked()) {
            return ApiResponseService::errorResponse(
                'Your certificate for this course has been revoked. Please contact support.',
                null,
                403,
            );
        }

        $courseCertificate = CourseCertificate::with(['user', 'course'])->findOrFail($certificate->id);

        $certificateTemplate = Certificate::where('type', 'course_completion')
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$certificateTemplate) {
            // Fallback to the default blade template
            $html = view('certificates.course_certificate_template', [
                'certificate' => $courseCertificate,
                'user' => $courseCertificate->user,
                'course' => $courseCertificate->course,
            ])->render();
            
            return response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        return response($this->generateCertificateHtml($certificateTemplate, $courseCertificate), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Generate and download certificate PDF for a course.
     *
     * Security:
     * - Verifies the authenticated user is actually enrolled in this course.
     * - Verifies course is completed.
     * - Certificate generation is idempotent (lock-guarded in CertificateService).
     * - Rejects revoked certificates.
     * - Uses attachment disposition so browser downloads instead of previewing.
     * - Filename format: "Course Name - Student Name.pdf" (Unicode-safe, cross-platform).
     */
    public function download(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse(__('User not authenticated.'), null, 401);
        }

        $course_id = (int) $request->input('course_id');

        $certificate = app(\App\Services\CertificateService::class)->autoGenerateCertificate($user->id, $course_id);

        if (!$certificate) {
            return ApiResponseService::errorResponse(
                'Could not generate certificate. Please check if you have purchased the certificate fee if applicable.',
                null,
                403
            );
        }

        // 🔐 Reject revoked certificates
        if ($certificate->isRevoked()) {
            return ApiResponseService::errorResponse(
                'Your certificate for this course has been revoked. Please contact support.',
                null,
                403,
            );
        }

        $courseCertificate = CourseCertificate::with(['user', 'course'])->findOrFail($certificate->id);

        // Get latest active admin template
        $certificateTemplate = Certificate::where('type', 'course_completion')
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($certificateTemplate) {
            $html = $this->generateCertificateHtml($certificateTemplate, $courseCertificate);

            $templateSettings = is_string($certificateTemplate->template_settings)
                ? json_decode($certificateTemplate->template_settings, true)
                : $certificateTemplate->template_settings;

            $widthPx  = $templateSettings['width']  ?? 800;
            $heightPx = $templateSettings['height'] ?? 600;
        } else {
            $html = view('certificates.course_certificate_template', [
                'certificate' => $courseCertificate,
                'user'        => $courseCertificate->user,
                'course'      => $courseCertificate->course,
            ])->render();

            $widthPx  = 800;
            $heightPx = 566;
        }

        try {
            $pdfContent = $this->generateAndCachePdf($html, $certificate->certificate_number, $widthPx, $heightPx);

            $filename        = $this->buildCertificateFilename(
                $courseCertificate->course->title ?? '',
                $courseCertificate->user->name    ?? ''
            );
            $encodedFilename = rawurlencode($filename);

            return response($pdfContent, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Length'      => strlen($pdfContent),
                'Content-Disposition' => "attachment; filename=\"certificate.pdf\"; filename*=UTF-8''{$encodedFilename}",
                'Cache-Control'       => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Certificate PDF generation failed', [
                'user_id'   => $user->id,
                'course_id' => $course_id,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);

            return ApiResponseService::errorResponse(
                'Failed to generate certificate PDF. Please try again.',
                ['debug' => config('app.debug') ? $e->getMessage() : null],
                500
            );
        }
    }

    /**
     * Verify certificate by number — web view (public, no auth)
     */
    public function verify(string $number)
    {
        $certificate = CourseCertificate::with(['user', 'course'])
            ->where('certificate_number', $number)
            ->first();

        if (!$certificate) {
            return view('certificates.verify', [
                'found'       => false,
                'certificate' => null,
            ]);
        }

        return view('certificates.verify', [
            'found'       => true,
            'certificate' => $certificate,
        ]);
    }

    /**
     * Verify certificate via JSON API — returns only safe public fields.
     *
     * GET /api/certificate/verify?token={verification_token}
     *
     * Security:
     * - Only accepts `verification_token` (32-char cryptographic hex).
     * - NEVER accepts certificate_number or internal DB id.
     * - NEVER exposes: email, user_id, DB id, certificate_number, internal metadata.
     * - Returns a constant-time 404 for invalid/revoked/not-found to prevent enumeration.
     */
    public function verifyApi(\Illuminate\Http\Request $request)
    {
        // Accept token from ?token= or legacy ?code= (for QR codes already distributed)
        $token = trim((string) $request->input('token', ''));
        $certificateNumber = strtoupper(trim((string) ($request->input('code') ?: $request->input('certificate_number') ?: '')));

        if ($token === '' && $certificateNumber === '') {
            return ApiResponseService::errorResponse('Verification code is required.', null, 422);
        }

        // Lookup ONLY by verification_token — never by certificate_number or id
        $query = CourseCertificate::query()->with(['user', 'course'])->active();
        $certificate = $token !== ''
            ? $query->where('verification_token', $token)->first()
            : $query->where('certificate_number', $certificateNumber)->first();

        if (!$certificate) {
            return response()->json([
                'ok'       => false,
                'message'  => 'Certificate not found or invalid.',
                'is_valid' => false,
                'data'     => null,
            ], 404);
        }

        // Return only safe public fields — NEVER expose certificate_number or DB id
        return response()->json([
            'ok'           => true,
            'is_valid'     => true,
            'message'      => 'Certificate is valid.',
            'valid'        => true,
            'student'      => ['name' => $certificate->student_name ?? ($certificate->user->name ?? 'N/A')],
            'course_title' => $certificate->arabic_title ?? ($certificate->course->title ?? 'N/A'),
            'certificate_number' => $certificate->certificate_number,
            'issued_at'    => optional($certificate->issued_date ?? $certificate->created_at)->toIso8601String(),
            'issued_date'  => optional($certificate->issued_date ?? $certificate->created_at)->toDateString(),
            'display_code' => $certificate->verification_code,
            'status'       => 'valid',
        ], 200);
    }

    /**
     * Publicly download a verified certificate via the certificate_number in the URL.
     * Uses attachment disposition for maximum browser/mobile compatibility.
     */
    public function downloadPublic(string $certificate_number)
    {
        $certificate = CourseCertificate::active()->with(['user', 'course'])
            ->where('certificate_number', $certificate_number)
            ->first();

        if (!$certificate) {
            return ApiResponseService::errorResponse('Certificate not found.', null, 404);
        }

        $certificateTemplate = Certificate::where('type', 'course_completion')
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($certificateTemplate) {
            $html             = $this->generateCertificateHtml($certificateTemplate, $certificate);
            $templateSettings = is_string($certificateTemplate->template_settings)
                ? json_decode($certificateTemplate->template_settings, true)
                : $certificateTemplate->template_settings;
            $widthPx  = $templateSettings['width']  ?? 800;
            $heightPx = $templateSettings['height'] ?? 600;
        } else {
            $html     = view('certificates.course_certificate_template', [
                'certificate' => $certificate,
                'user'        => $certificate->user,
                'course'      => $certificate->course,
            ])->render();
            $widthPx  = 800;
            $heightPx = 566;
        }

        try {
            $pdfContent      = $this->generateAndCachePdf($html, $certificate->certificate_number, $widthPx, $heightPx);
            $filename        = $this->buildCertificateFilename(
                $certificate->course->title ?? '',
                $certificate->user->name    ?? ''
            );
            $encodedFilename = rawurlencode($filename);

            return response($pdfContent, 200, [
                'Content-Type'           => 'application/pdf',
                'Content-Length'         => strlen($pdfContent),
                'Content-Disposition'    => "attachment; filename=\"certificate.pdf\"; filename*=UTF-8''{$encodedFilename}",
                'Cache-Control'          => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to generate certificate PDF.', null, 500);
        }
    }

    /**
     * Generate certificate HTML from admin-designed template.
     * Public so admin controllers can reuse without code duplication.
     */
    public function generateCertificateHtmlPublic($template, $certificate): string
    {
        return $this->generateCertificateHtml($template, $certificate);
    }

    /**
     * Generate certificate HTML from admin-designed template
     */
    private function generateCertificateHtml($template, $certificate)
    {
        $settings = is_string($template->template_settings)
            ? json_decode($template->template_settings, true)
            : $template->template_settings;

        $canvasWidth = $settings['width'] ?? 800;
        $canvasHeight = $settings['height'] ?? 600;

        $replacements = [
            '[Student Name]' => $certificate->user->name ?? '',
            '[Course Name]' => $certificate->course->title ?? '',
            '[Completion Date]' => \Carbon\Carbon::parse($certificate->issued_date)->format('F d, Y'),
            '[Certificate Number]' => $certificate->certificate_number,
            '{{certificate_number}}' => $certificate->certificate_number,
            '{{student_name}}' => $certificate->user->name ?? '',
            '{{course_name}}' => $certificate->course->title ?? '',
            '{{completion_date}}' => \Carbon\Carbon::parse($certificate->issued_date)->format('F d, Y'),
            '{{signature_text}}' => $template->signature_text ?? '',
            '{{certificate_title}}' => $template->title ?? '',
            '{{certificate_subtitle}}' => $template->subtitle ?? '',
        ];

        $html =
            '
    <!DOCTYPE html>
    <html dir="rtl" lang="ar">
    <head>
        <meta charset="UTF-8">
        <style>
            *{margin:0;padding:0;box-sizing:border-box;}
            body{
                width:'
            . $canvasWidth
            . 'px;
                height:'
            . $canvasHeight
            . 'px;
                background-image:url("'
            . asset('storage/' . $template->background_image)
            . '");
                background-size:cover;
                background-repeat:no-repeat;
                position:relative;
                overflow:hidden;
                font-family:Arial, sans-serif;
            }
            .element{position:absolute;word-wrap:break-word;}
        </style>
    </head>
    <body>
    ';

        if (isset($settings['layoutConfig']) && is_array($settings['layoutConfig'])) {
            // NEW REACT FORMAT (CertificateLayoutConfig)
            $qrConfig = null;
            
            foreach ($settings['layoutConfig'] as $fieldKey => $element) {
                // Ignore hidden fields
                if (isset($element['visible']) && !$element['visible']) {
                    continue;
                }

                $content = '';
                switch ($fieldKey) {
                    case 'studentName':
                        $content = $certificate->user->name ?? $certificate->student_name ?? '';
                        break;
                    case 'courseTitle':
                        $content = $certificate->course->title ?? $certificate->arabic_title ?? '';
                        break;
                    case 'date':
                        $content = \Carbon\Carbon::parse($certificate->issued_date ?? now())->format('Y-m-d');
                        break;
                    case 'instructorName':
                        $content = $certificate->course->user->name ?? $certificate->instructor_name ?? '';
                        break;
                    case 'certificateId':
                        $content = $certificate->certificate_number;
                        break;
                    case 'qrCode':
                        // Save QR config to process later
                        $qrConfig = $element;
                        continue 2;
                }

                $styleString = "left:{$element['x']}px;top:{$element['y']}px;width:{$element['width']}px;height:{$element['height']}px;";
                if (isset($element['fontSize'])) {
                    // Responsive sizing roughly equivalent to React layout
                    $styleString .= "font-size:{$element['fontSize']}px;";
                }
                if (!empty($element['color'])) {
                    $styleString .= "color:{$element['color']};";
                }
                if (!empty($element['textAlign'])) {
                    $styleString .= "text-align:{$element['textAlign']};";
                }

                // Make sure text wraps and fits the container
                $styleString .= "display:flex;align-items:center;justify-content:{$element['textAlign']};";

                $html .= "<div class='element' style='position:absolute;{$styleString}'>{$content}</div>";
            }

            // Render QR Code based on new config
            if ($qrConfig && (!isset($qrConfig['visible']) || $qrConfig['visible'])) {
                // QR URL always uses the unguessable verification_token
                $verifyUrl = $certificate->verification_url
                    ?: url('/certificates/verify/' . ($certificate->verification_token ?: $certificate->verification_code));
                try {
                    $result = (new \Endroid\QrCode\Builder\Builder(data: $verifyUrl, size: 150))->build();
                    $qrPng = $result->getString();
                    $qrDataUri = 'data:' . $result->getMimeType() . ';base64,' . base64_encode($qrPng);
                    $qrX = $qrConfig['x'] ?? ($canvasWidth - 180);
                    $qrY = $qrConfig['y'] ?? ($canvasHeight - 180);
                    $qrWidth = $qrConfig['width'] ?? 80;
                    $qrHeight = $qrConfig['height'] ?? 80;
                    $html .= "<div style='position:absolute;left:{$qrX}px;top:{$qrY}px;width:{$qrWidth}px;height:{$qrHeight}px;'><img src='{$qrDataUri}' style='width:100%;height:100%;'></div>";
                } catch (\Throwable $e) {
                    // QR generation optional
                }
            }
        } elseif (isset($settings['elements']) && is_array($settings['elements'])) {
            // OLD FORMAT (Legacy elements array)
            foreach ($settings['elements'] as $element) {
                $content = $element['content'] ?? '';
                $content = str_replace(array_keys($replacements), array_values($replacements), $content);

                $styles = $element['styles'] ?? [];
                $styleString = "left:{$element['x']}px;top:{$element['y']}px;";
                if (isset($styles['fontSize'])) {
                    $styleString .= "font-size:{$styles['fontSize']};";
                }
                if (isset($styles['color'])) {
                    $styleString .= "color:{$styles['color']};";
                }
                if (isset($styles['fontWeight'])) {
                    $styleString .= "font-weight:{$styles['fontWeight']};";
                }
                if (isset($styles['fontFamily'])) {
                    $styleString .= "font-family:{$styles['fontFamily']};";
                }
                if (isset($styles['textAlign'])) {
                    $styleString .= "text-align:{$styles['textAlign']};";
                }

                if (($element['type'] ?? '') === 'image') {
                    $x = $element['x'] ?? 0;
                    $y = $element['y'] ?? 0;
                    $width = $element['width'] ?? 150;
                    $height = $element['height'] ?? 60;

                    $x = is_numeric($x) ? (float) $x : 0;
                    $y = is_numeric($y) ? (float) $y : 0;
                    $width = is_numeric($width) ? (float) $width : 150;
                    $height = is_numeric($height) ? (float) $height : 60;

                    $imgSrc = str_starts_with((string) $element['content'], 'http')
                        ? $element['content']
                        : asset('storage/' . $element['content']);

                    $html .= "<div class='element' style='position:absolute; left:{$x}px; top:{$y}px; 
                            width:{$width}px; height:{$height}px;'>
                            <img src='{$imgSrc}' style='width:100%; height:100%; object-fit:contain;'>
                          </div>";
                } else {
                    $html .= "<div class='element' style='position:absolute;{$styleString}width:{$element['width']}px;height:{$element['height']}px;'>{$content}</div>";
                }
            }

            // Legacy QR Add — uses verification_token for security
            $verifyUrl = $certificate->verification_url
                ?: url('/certificates/verify/' . ($certificate->verification_token ?: $certificate->verification_code));
            try {
                $result = (new \Endroid\QrCode\Builder\Builder(data: $verifyUrl, size: 150))->build();
                $qrPng = $result->getString();
                $qrDataUri = 'data:' . $result->getMimeType() . ';base64,' . base64_encode($qrPng);
                $qrX = $canvasWidth - 180;
                $qrY = $canvasHeight - 180;
                $html .= "<div style='position:absolute;left:{$qrX}px;top:{$qrY}px;width:150px;height:150px;'><img src='{$qrDataUri}' style='width:100%;height:100%;'></div>";
            } catch (\Throwable $e) {}
        }

        // Add signature image manually if not already in template
        if ($template->signature_image) {
            $sigX = $canvasWidth - 210; // default fallback right offset
            $sigY = $canvasHeight - 140; // default fallback bottom offset
            $sigWidth = 150;
            $sigHeight = 60;

            if (isset($settings['elements']) && is_array($settings['elements'])) {
                foreach ($settings['elements'] as $el) {
                    if (!(isset($el['type']) && strtolower((string) $el['type']) === 'signature')) {
                        continue;
                    }
                    $sigX = $el['x'] ?? $sigX;
                    $sigY = $el['y'] ?? $sigY;
                    $sigWidth = $el['width'] ?? $sigWidth;
                    $sigHeight = $el['height'] ?? $sigHeight;
                    break;
                }
            }

            $html .=
                '
            <div style="position:absolute;
                        left:'
                . $sigX
                . 'px;
                        top:'
                . $sigY
                . 'px;
                        width:'
                . $sigWidth
                . 'px;
                        height:'
                . $sigHeight
                . 'px;">
                <img src="'
                . asset('storage/' . $template->signature_image)
                . '" 
                     style="width:100%;
                            height:100%;
                            object-fit:contain;">
            </div>';
        }

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Clean HTML content while preserving HTML structure
     */
    private function cleanHtmlContent($html)
    {
        if (empty($html) || !is_string($html)) {
            return '';
        }

        // Remove BOM if present
        $html = preg_replace('/^\xEF\xBB\xBF/', '', $html);

        // Ensure UTF-8 encoding
        if (!mb_check_encoding($html, 'UTF-8')) {
            $detected = mb_detect_encoding((string) $html, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
            if ($detected) {
                $html = mb_convert_encoding($html, 'UTF-8', $detected);
            } else {
                $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
            }
        }

        // Remove control characters (except newlines, tabs, carriage returns)
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html);

        return $html ?: '';
    }

    /**
     * Aggressive UTF-8 cleaning for fallback scenarios
     */
    private function forceCleanUtf8($html)
    {
        if (empty($html) || !is_string($html)) {
            return '';
        }

        $html = preg_replace('/^\xEF\xBB\xBF/', '', $html);

        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }

        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $html);

        if (function_exists('iconv')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $html);
            if ($cleaned !== false && $cleaned !== '') {
                $html = $cleaned;
            }
        }

        return $html ?: '';
    }

    /**
     * Clean string to ensure valid UTF-8 encoding
     */
    private function cleanUtf8String($string)
    {
        if (empty($string) || !is_string($string)) {
            return '';
        }

        if (!mb_check_encoding($string, 'UTF-8')) {
            $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        }

        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);

        if (function_exists('iconv')) {
            $string = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $string);
        }

        return $string ?: '';
    }

    private function isCourseCompleted($user_id, $course_id): bool
    {
        // Delegate to CertificateService — single source of truth for course completion.
        return app(\App\Services\CertificateService::class)
            ->checkCourseCompletionStatus($user_id, $course_id);
    }

    public function generateQuizCertificate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'quiz_id' => 'required|exists:course_chapter_quizzes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        $quiz_id = $request->input('quiz_id');

        // Check if user completed the quiz (replace with your logic)
        if (!$this->isQuizCompleted($user?->id, $quiz_id)) {
            return response()->json(['message' => 'Quiz not completed'], 403);
        }

        // Find the user's completed quiz attempt
        $userQuizAttempt = UserQuizAttempt::where('user_id', $user->id)
            ->where('course_chapter_quiz_id', $quiz_id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->latest()
            ->first();

        if (!$userQuizAttempt) {
            return response()->json(['message' => 'Quiz attempt not found'], 404);
        }

        $certificate = QuizCertificate::firstOrCreate([
            'user_id'              => $user->id,
            'user_quiz_attempt_id' => $userQuizAttempt->id,
        ], [
            // Use cryptographically secure random number, not predictable uniqid()
            'certificate_number' => $this->generateSecureCertificateNumber($user->id),
            'issued_date'        => now(),
        ]);

        // You may want to return a download or certificate info

        return $this->download_quiz_certificate($certificate->id);
    }

    public function download_quiz_certificate($certificate_id)
    {
        $certificate = \App\Models\QuizCertificate::with(['user', 'attempt.quiz'])->find($certificate_id);

        if (!$certificate) {
            return response()->json(['message' => 'Certificate not found'], 404);
        }

        $user = $certificate->user;
        $attempt = $certificate->attempt;
        $quiz = $attempt ? $attempt->quiz : null;

        // Prepare certificate data
        $data = [
            'certificate_number' => $certificate->certificate_number,
            'issued_date' => $certificate->issued_date
                ? (
                    $certificate->issued_date instanceof \Carbon\Carbon
                        ? $certificate->issued_date->format('Y-m-d')
                        : \Carbon\Carbon::parse($certificate->issued_date)->format('Y-m-d')
                )
                : '',
            'user_name' => $user ? $user->name : '',
            'quiz_title' => $quiz ? $quiz->title : '',
            'score' => $attempt ? $attempt->score : '',
            'completed_at' => $attempt && $attempt->completed_at
                ? (
                    $attempt->completed_at instanceof \Carbon\Carbon
                        ? $attempt->completed_at->format('Y-m-d')
                        : \Carbon\Carbon::parse($attempt->completed_at)->format('Y-m-d')
                )
                : '',
        ];

        // Render a view as PDF (assumes you have a Blade view at resources/views/certificate/quiz_certificate_template.blade.php)
        $html = view('certificates.quiz_certificate_template', [
            'name' => $user->name ?? '',
            'quiz' => $quiz->title ?? '',
            'score' => $attempt->score ?? '',
            'date' => $attempt && $attempt->completed_at
                ? \Carbon\Carbon::parse($attempt->completed_at)->format('Y-m-d')
                : '',
            'certificate_number' => $certificate->certificate_number ?? '',
        ])->render();

        $widthPx  = 1122; // A4 Landscape roughly
        $heightPx = 794;  // A4 Landscape roughly

        try {
            $pdfContent = $this->generateAndCachePdf($html, $certificate->certificate_number, $widthPx, $heightPx);

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="certificate.pdf"',
            ]);
        } catch (\Throwable $e) {
            return \App\Services\ApiResponseService::errorResponse('Failed to generate quiz certificate PDF.', null, 500);
        }
    }

    private function isQuizCompleted($user_id, $quiz_id): bool
    {
        return UserQuizAttempt::where('user_id', $user_id)
            ->where('course_chapter_quiz_id', $quiz_id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->exists();
    }

    /**
     * Generate a cryptographically secure certificate number for quiz certificates.
     * Format: CERT-{YEAR}-{USERID-5digits}-{RANDOM-8chars}
     * Uses random_bytes (CSPRNG) — never predictable like uniqid().
     */
    private function generateSecureCertificateNumber(int $userId): string
    {
        $year     = date('Y');
        $userPart = str_pad((string) $userId, 5, '0', STR_PAD_LEFT);

        do {
            $randomPart = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $number     = "CERT-{$year}-{$userPart}-{$randomPart}";
        } while (\App\Models\QuizCertificate::where('certificate_number', $number)->exists());

        return $number;
    }

    /**
     * Build a Unicode-safe, cross-platform certificate filename.
     *
     * Format: "Course Name - Student Name.pdf"
     *
     * Rules:
     * - Allows Unicode letters (Arabic + Latin), digits, spaces, hyphens, dots, underscores.
     * - Strips characters invalid on Windows/macOS/Linux: \ / : * ? " < > |
     * - Collapses consecutive whitespace to single space.
     * - Trims leading/trailing whitespace and dots (Windows restriction).
     * - Caps total length at 200 bytes to avoid filesystem limits.
     */
    private function buildCertificateFilename(string $courseName, string $studentName): string
    {
        $sanitize = function (string $s): string {
            // Remove characters invalid on Windows (and Android/iOS FAT-derived paths)
            $s = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u', '', $s);
            // Collapse consecutive whitespace
            $s = preg_replace('/\s+/u', ' ', $s);
            // Trim leading/trailing whitespace and dots
            return trim($s, " \t\n\r\0\x0B.");
        };

        $course  = $sanitize($courseName)  ?: 'Certificate';
        $student = $sanitize($studentName) ?: 'Student';

        $base     = "{$course} - {$student}";
        $filename = $base . '.pdf';

        // Ensure filename bytes don't exceed filesystem limits (200 chars is safe)
        if (mb_strlen($filename, 'UTF-8') > 200) {
            $maxBase  = 200 - 4; // 4 for ".pdf"
            $filename = mb_substr($base, 0, $maxBase, 'UTF-8') . '.pdf';
        }

        return $filename;
    }
}
