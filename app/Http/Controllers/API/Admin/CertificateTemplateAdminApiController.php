<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Certificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CertificateTemplateAdminApiController extends AdminCrudApiController
{
    /**
     * Get the certificate template for a specific type (defaults to 'course_completion')
     * GET /api/admin/certificate-templates?type=course_completion
     */
    public function getTemplate(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('certificates-list');

        $type = $request->input('type', 'course_completion');

        $template = Certificate::where('type', $type)->first();

        if (!$template) {
            // Return an empty template structure if not found, rather than 404
            return $this->jsonSuccess(__('Certificate template not found, please create one.'), null);
        }

        return $this->jsonSuccess(
            __('Certificate template retrieved successfully'),
            $template->append(['background_image_url', 'signature_image_url'])
        );
    }

    /**
     * Save or update the certificate template in-place
     * POST /api/admin/certificate-templates
     */
    public function upsertTemplate(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('certificates-edit');

        // Handle template_settings if it's sent as a JSON string
        if ($request->has('template_settings') && is_string($request->template_settings)) {
            $decodedSettings = json_decode($request->template_settings, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge(['template_settings' => $decodedSettings]);
            }
        }

        $validator = Validator::make($request->all(), [
            'type' => 'nullable|in:course_completion,exam_completion,custom',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:500',
            'signature_text' => 'nullable|string|max:255',
            'template_settings' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'background_image' => [
                'nullable',
                'file',
                'mimes:jpeg,png,jpg,gif,svg,webp',
                'max:2048',
            ],
            'signature_image' => [
                'nullable',
                'file',
                'mimes:jpeg,png,jpg,gif,svg,webp',
                'max:1024',
            ],
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $type = $request->input('type', 'course_completion');
        
        $template = Certificate::where('type', $type)->first();

        try {
            $data = $request->only([
                'name',
                'description',
                'title',
                'subtitle',
                'signature_text',
                'template_settings',
            ]);

            // Default values for new template if not provided
            if (!$template) {
                $data['name'] = $data['name'] ?? ucwords(str_replace('_', ' ', $type));
                $data['type'] = $type;
                $data['is_active'] = $request->input('is_active', true);
            } else {
                if ($request->has('is_active')) {
                    $data['is_active'] = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
                }
            }

            // Handle background image upload
            if ($request->hasFile('background_image')) {
                if ($template && $template->background_image) {
                    Storage::disk('public')->delete($template->background_image);
                }

                $backgroundImage = $request->file('background_image');
                $backgroundImageName = 'certificate_bg_' . time() . '.' . $backgroundImage->getClientOriginalExtension();
                $backgroundImagePath = $backgroundImage->storeAs('certificates/backgrounds', $backgroundImageName, 'public');
                $data['background_image'] = $backgroundImagePath;
            }

            // Handle signature image upload
            if ($request->hasFile('signature_image')) {
                if ($template && $template->signature_image) {
                    Storage::disk('public')->delete($template->signature_image);
                }

                $signatureImage = $request->file('signature_image');
                $signatureImageName = 'certificate_signature_' . time() . '.' . $signatureImage->getClientOriginalExtension();
                $signatureImagePath = $signatureImage->storeAs('certificates/signatures', $signatureImageName, 'public');
                $data['signature_image'] = $signatureImagePath;
            }

            // Handle template settings cleanup
            if ($request->has('template_settings') && $request->template_settings === null) {
                $data['template_settings'] = null;
            }

            if ($template) {
                $template->update($data);
            } else {
                $template = Certificate::create(array_merge(['type' => $type], $data));
            }

            return $this->jsonSuccess('Certificate template saved successfully', $template);
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to save certificate template: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Generate a live PDF preview based on provided layout settings.
     * POST /api/admin/certificate-templates/preview
     */
    public function previewPdf(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('certificates-edit');

        $type = $request->input('type', 'course_completion');
        $template = Certificate::where('type', $type)->first();

        if (!$template) {
            $template = new Certificate([
                'type' => $type,
                'name' => 'Default Certificate Template',
                'is_active' => true,
            ]);
        }

        // Apply any incoming settings over the existing ones
        $currentSettings = is_string($template->template_settings)
            ? json_decode($template->template_settings, true)
            : ($template->template_settings ?? []);
        $currentSettings = is_array($currentSettings) ? $currentSettings : [];
        $layoutConfig = $request->input('layoutConfig');
        if ($layoutConfig && is_array($layoutConfig)) {
            $currentSettings['layoutConfig'] = $layoutConfig;
        }

        // Create dummy certificate
        $dummyCertificate = new \App\Models\Course\CourseCertificate([
            'certificate_number' => $request->input('certificateId', 'PREVIEW-' . date('Ymd')),
            'issued_date' => now(),
            'student_name' => $request->input('studentName', 'أحمد محمد'),
            'arabic_title' => $request->input('arabicCourseTitle', 'دورة تجريبية لمعاينة الشهادة'),
            'english_title' => $request->input('englishCourseTitle', 'SAMPLE COURSE FOR PREVIEW'),
            'instructor_name' => $request->input('instructorName', 'اسم المدرب'),
        ]);

        $dummyCertificate->setRelation('user', new \App\Models\User(['name' => $request->input('studentName', 'أحمد محمد')]));
        $dummyCourse = new \App\Models\Course\Course(['title' => $request->input('arabicCourseTitle', 'دورة تجريبية لمعاينة الشهادة')]);
        $dummyCourse->setRelation('user', new \App\Models\User(['name' => $request->input('instructorName', 'اسم المدرب')]));
        $dummyCertificate->setRelation('course', $dummyCourse);

        $html = app(\App\Http\Controllers\CertificateController::class)->generateCertificateHtmlPublic($template, $dummyCertificate);

        $widthPx  = $currentSettings['width'] ?? 1200;
        $heightPx = $currentSettings['height'] ?? 800;
        $widthMM  = round($widthPx  * 0.264583, 2);
        $heightMM = round($heightPx * 0.264583, 2);

        $mpdf = new \Mpdf\Mpdf([
            'mode'             => 'utf-8',
            'format'           => [$widthMM, $heightMM],
            'margin_left'      => 0,
            'margin_right'     => 0,
            'margin_top'       => 0,
            'margin_bottom'    => 0,
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
            'tempDir'          => storage_path('app/temp'),
        ]);

        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');

        return response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
        ]);
    }
}
