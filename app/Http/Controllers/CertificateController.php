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
     * - Verifies course is completed + video progress = 100%.
     * - Certificate generation is idempotent (firstOrCreate).
     * - Rejects revoked certificates.
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
            // Generate HTML → PDF from database template
            $html = $this->generateCertificateHtml($certificateTemplate, $courseCertificate);

            $templateSettings = is_string($certificateTemplate->template_settings)
                ? json_decode($certificateTemplate->template_settings, true)
                : $certificateTemplate->template_settings;

            $widthPx  = $templateSettings['width']  ?? 800;
            $heightPx = $templateSettings['height'] ?? 600;
        } else {
            // Fallback to the default blade template
            $html = view('certificates.course_certificate_template', [
                'certificate' => $courseCertificate,
                'user'        => $courseCertificate->user,
                'course'      => $courseCertificate->course,
            ])->render();

            $widthPx  = 800; // Standard fallback width
            $heightPx = 566; // Matches course_certificate_template dimensions
        }

        $widthMM  = round($widthPx  * 0.264583, 2);
        $heightMM = round($heightPx * 0.264583, 2);

        try {
            $pdfContent = $this->generateAndCachePdf($html, $certificate->certificate_number, $widthPx, $heightPx);

            return response($pdfContent, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="certificate-' . $certificate->certificate_number . '.pdf"',
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
     * GET /api/certificate/verify?code=CERT-XXXX
     *
     * Never exposes: email, user_id, internal IDs, private metadata.
     */
    public function verifyApi(\Illuminate\Http\Request $request)
    {
        $code = trim((string) ($request->input('code') ?: $request->input('certificate_number') ?: ''));

        if (empty($code)) {
            return ApiResponseService::errorResponse('Verification code is required.', null, 422);
        }

        $certificate = CourseCertificate::with(['user', 'course'])
            ->where('certificate_number', $code)
            ->first();

        if (!$certificate) {
            return response()->json([
                'ok' => false,
                'message' => 'No certificate found with this verification code.',
                'is_valid' => false,
                'data' => null
            ], 404);
        }

        if ($certificate->isRevoked()) {
            return response()->json([
                'ok' => true,
                'message' => 'Certificate has been revoked',
                'is_valid' => false,
                'data' => null
            ], 200);
        }

        // Return only safe public fields — mapped exactly to frontend requirements
        return response()->json([
            'ok' => true,
            'is_valid' => true,
            'data' => [
                'studentName'        => $certificate->student_name ?? ($certificate->user->name ?? 'N/A'),
                'arabicCourseTitle'  => $certificate->arabic_title ?? ($certificate->course->title ?? 'N/A'),
                'englishCourseTitle' => $certificate->english_title ?? ($certificate->course->title ?? 'N/A'),
                'date'               => optional($certificate->issued_date)->format('Y-m-d'),
                'instructorName'     => $certificate->instructor_name ?? ($certificate->course->user->name ?? 'N/A'),
                'certificateId'      => $certificate->certificate_number,
                'courseId'           => $certificate->course_id,
                'issued_at'          => optional($certificate->created_at)->toIso8601String(),
            ]
        ], 200);
    }

    /**
     * Publicly download a verified certificate.
     */
    public function downloadPublic(string $certificate_number)
    {
        $certificate = CourseCertificate::with(['user', 'course'])
            ->where('certificate_number', $certificate_number)
            ->first();

        if (!$certificate) {
            return ApiResponseService::errorResponse('Certificate not found.', null, 404);
        }

        if ($certificate->isRevoked()) {
            return ApiResponseService::errorResponse('Certificate has been revoked.', null, 403);
        }

        $certificateTemplate = Certificate::where('type', 'course_completion')
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($certificateTemplate) {
            $html = $this->generateCertificateHtml($certificateTemplate, $certificate);
            $templateSettings = is_string($certificateTemplate->template_settings)
                ? json_decode($certificateTemplate->template_settings, true)
                : $certificateTemplate->template_settings;
            $widthPx  = $templateSettings['width']  ?? 800;
            $heightPx = $templateSettings['height'] ?? 600;
        } else {
            $html = view('certificates.course_certificate_template', [
                'certificate' => $certificate,
                'user'        => $certificate->user,
                'course'      => $certificate->course,
            ])->render();
            $widthPx  = 800;
            $heightPx = 566;
        }

        try {
            $pdfContent = $this->generateAndCachePdf($html, $certificate->certificate_number, $widthPx, $heightPx);

            return response($pdfContent, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="certificate-' . $certificate->certificate_number . '.pdf"',
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
    <html>
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

        if (isset($settings['elements']) && is_array($settings['elements'])) {
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

                    // Ensure they are numeric
                    $x = is_numeric($x) ? (float) $x : 0;
                    $y = is_numeric($y) ? (float) $y : 0;
                    $width = is_numeric($width) ? (float) $width : 150;
                    $height = is_numeric($height) ? (float) $height : 60;

                    // Fix: mPDF ignores CSS top/left for images sometimes.
                    // Use absolute positioned DIV wrapper with proper dimensions.
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
        }

        // Add signature image manually if not already in template
        if ($template->signature_image) {
            $sigX = $canvasWidth - 210; // default fallback right offset
            $sigY = $canvasHeight - 140; // default fallback bottom offset
            $sigWidth = 150;
            $sigHeight = 60;

            // ✅ Check if template_settings has a signature element (use its exact position)
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

            // ✅ Wrap inside <div> so mPDF respects top/left coordinates
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

        // Add QR code for verification (T088)
        $verifyUrl = url('/certificate/verify/' . $certificate->certificate_number);
        try {
            $result = (new \Endroid\QrCode\Builder\Builder(data: $verifyUrl, size: 150))->build();
            $qrPng = $result->getString();
            $qrDataUri = 'data:' . $result->getMimeType() . ';base64,' . base64_encode($qrPng);
            $qrX = $canvasWidth - 180;
            $qrY = $canvasHeight - 180;
            $html .= "<div style='position:absolute;left:{$qrX}px;top:{$qrY}px;width:150px;height:150px;'><img src='{$qrDataUri}' style='width:100%;height:100%;'></div>";
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // QR generation optional - skip if fails
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
            'user_id' => $user->id,
            'user_quiz_attempt_id' => $userQuizAttempt->id,
        ], [
            'certificate_number' => strtoupper(uniqid('CERT-')),
            'issued_date' => now(),
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
        // Check if the user has a completed quiz attempt with completed_at not null
        return UserQuizAttempt::where('user_id', $user_id)
            ->where('course_chapter_quiz_id', $quiz_id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->exists();
    }
}
