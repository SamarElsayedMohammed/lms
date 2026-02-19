<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Course\Course;
use App\Models\Course\CourseCertificate;
use App\Models\Course\CourseChapter\Quiz\UserQuizAttempt;
use App\Models\QuizCertificate;
use App\Services\ApiResponseService;
use App\Services\VideoProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Mpdf\Mpdf;

class CertificateController extends Controller
{
    /**
     * Get certificate details for a course (check if certificate exists)
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

        $course_id = $request->input('course_id');

        // Get certificate if exists
        $certificate = CourseCertificate::with(['user', 'course'])
            ->where('user_id', $user->id)
            ->where('course_id', $course_id)
            ->first();

        if (!$certificate) {
            // Check if course is completed
            $isCompleted = $this->isCourseCompleted($user->id, $course_id);

            return ApiResponseService::successResponse(__('Certificate not found.'), [
                'certificate_exists' => false,
                'course_completed' => $isCompleted,
                'message' => $isCompleted
                    ? __('Course is completed. You can generate certificate.')
                    : __('Course is not completed yet.'),
            ]);
        }

        return ApiResponseService::successResponse(__('Certificate found.'), [
            'certificate_exists' => true,
            'certificate' => [
                'id' => $certificate->id,
                'certificate_number' => $certificate->certificate_number,
                'issued_date' => $certificate->issued_date,
                'course' => [
                    'id' => $certificate->course->id ?? null,
                    'title' => $certificate->course->title ?? null,
                ],
                'user' => [
                    'id' => $certificate->user->id ?? null,
                    'name' => $certificate->user->name ?? null,
                ],
            ],
        ]);
    }

    /**
     * Generate and download certificate PDF for a course
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

        $course_id = $request->input('course_id');

        if (!$this->isCourseCompleted($user->id, $course_id)) {
            return ApiResponseService::validationError(
                'Course not completed. Please complete all lessons, quizzes, and assignments to generate certificate.',
            );
        }

        $course = Course::findOrFail($course_id);
        $videoProgress = app(VideoProgressService::class)->getCourseProgress($user, $course);
        if ($videoProgress < 100.0) {
            return ApiResponseService::validationError(
                'You must watch all video lectures to 100% before generating a certificate. Current progress: ' . $videoProgress . '%',
            );
        }

        $certificate = CourseCertificate::firstOrCreate(['user_id' => $user->id, 'course_id' => $course_id], [
            'certificate_number' => strtoupper(uniqid('CERT-')),
            'issued_date' => now(),
        ]);

        $courseCertificate = CourseCertificate::with(['user', 'course'])->findOrFail($certificate->id);

        // Get latest active admin template
        $certificateTemplate = Certificate::where('type', 'course_completion')
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$certificateTemplate) {
            return ApiResponseService::errorResponse('No active certificate template found.');
        }

        // Generate HTML
        $html = $this->generateCertificateHtml($certificateTemplate, $courseCertificate);

        // Prepare PDF dimensions
        $templateSettings = is_string($certificateTemplate->template_settings)
            ? json_decode($certificateTemplate->template_settings, true)
            : $certificateTemplate->template_settings;

        $widthPx = $templateSettings['width'] ?? 800;
        $heightPx = $templateSettings['height'] ?? 600;
        $widthMM = round($widthPx * 0.264583, 2);
        $heightMM = round($heightPx * 0.264583, 2);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [$widthMM, $heightMM],
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificate.pdf"',
        ]);
    }

    /**
     * Verify certificate by number (T089) - public, no auth
     */
    public function verify(string $number)
    {
        $certificate = CourseCertificate::with(['user', 'course'])
            ->where('certificate_number', $number)
            ->first();

        if (!$certificate) {
            return view('certificates.verify', [
                'found' => false,
                'certificate' => null,
            ]);
        }

        return view('certificates.verify', [
            'found' => true,
            'certificate' => $certificate,
        ]);
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
        // Get course with chapters and curriculum items
        $course = Course::with([
            'chapters' => static function ($query): void {
                $query->where('is_active', 1)->orderBy('chapter_order');
            },
            'chapters.lectures' => static function ($query): void {
                $query->where('is_active', 1);
            },
            'chapters.quizzes' => static function ($query): void {
                $query->where('is_active', 1);
            },
            'chapters.assignments' => static function ($query): void {
                $query->where('is_active', 1);
            },
            'chapters.resources' => static function ($query): void {
                $query->where('is_active', 1);
            },
        ])->find($course_id);

        if (!$course) {
            return false;
        }

        // Count total curriculum items (excluding assignments)
        $totalLectures = 0;
        $totalQuizzes = 0;
        $totalResources = 0;

        foreach ($course->chapters as $chapter) {
            $totalLectures += $chapter->lectures->count();
            $totalQuizzes += $chapter->quizzes->count();
            $totalResources += $chapter->resources->count();
        }

        // Check completed items from user_curriculum_trackings table
        $completedTracking = \App\Models\UserCurriculumTracking::where('user_id', $user_id)
            ->whereIn('course_chapter_id', $course->chapters->pluck('id'))
            ->where('status', 'completed')
            ->get();

        $completedLectures = $completedTracking
            ->where('model_type', \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture::class)
            ->count();
        $completedQuizzes = $completedTracking
            ->where('model_type', \App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz::class)
            ->count();
        $completedResources = $completedTracking
            ->where('model_type', \App\Models\Course\CourseChapter\Resource\CourseChapterResource::class)
            ->count();

        // Check if all curriculum items are completed
        $curriculumItemsTotal = $totalLectures + $totalQuizzes + $totalResources;
        $curriculumItemsCompleted = $completedLectures + $completedQuizzes + $completedResources;
        $allCurriculumCompleted = $curriculumItemsTotal == 0 || $curriculumItemsCompleted >= $curriculumItemsTotal;

        // Check assignment submissions (must be submitted or accepted, or can_skip = 1)
        $assignmentIds = [];
        $skippableAssignmentIds = [];
        foreach ($course->chapters as $chapter) {
            foreach ($chapter->assignments as $assignment) {
                $assignmentIds[] = $assignment->id;
                if ($assignment->can_skip) {
                    $skippableAssignmentIds[] = $assignment->id;
                }
            }
        }

        $totalAssignments = count($assignmentIds);
        $skippableAssignments = count($skippableAssignmentIds);
        $submittedAssignments = 0;

        if (!empty($assignmentIds)) {
            // Count assignments that have been submitted/accepted (excluding skippable ones)
            $nonSkippableAssignmentIds = array_diff($assignmentIds, $skippableAssignmentIds);
            if (!empty($nonSkippableAssignmentIds)) {
                $submittedAssignments = \App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission::where(
                    'user_id',
                    $user_id,
                )
                    ->whereIn('course_chapter_assignment_id', $nonSkippableAssignmentIds)
                    ->whereIn('status', ['submitted', 'accepted'])
                    ->count();
            }
        }

        $allAssignmentsSubmitted = \App\Services\CourseCompletionService::allAssignmentsSubmitted(
            $totalAssignments,
            $skippableAssignments,
            $submittedAssignments,
        );

        // Course is completed only if both conditions are met
        return $allCurriculumCompleted && $allAssignmentsSubmitted;
    }

    public function generateQuizCertificate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'quiz_id' => 'required|exists:quiz_questions,id',
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

        $mpdf = new \Mpdf\Mpdf(['format' => 'A4-L']); // Landscape A4
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificate.pdf"',
        ]);
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
