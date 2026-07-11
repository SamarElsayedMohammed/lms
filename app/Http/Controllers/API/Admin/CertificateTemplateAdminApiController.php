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

        return $this->jsonSuccess(__('Certificate template retrieved successfully'), $template);
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
                $message = __('Certificate template updated successfully');
            } else {
                $template = Certificate::create($data);
                $message = __('Certificate template created successfully');
            }

            return $this->jsonSuccess($message, $template);
        } catch (\Exception $e) {
            return $this->jsonError('Failed to save certificate template: ' . $e->getMessage(), 500);
        }
    }
}
