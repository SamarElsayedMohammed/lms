<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CertificateController extends Controller
{
    /**
     * Display a listing of certificates
     */
    public function index(Request $request)
    {
        // If it's an AJAX request, return JSON data for the table
        if ($request->ajax() || $request->wantsJson()) {
            $query = Certificate::query();

            // Apply search filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Get sort parameters
            $sort = $request->get('sort', 'id');
            $order = $request->get('order', 'DESC');

            // Apply sorting
            $query->orderBy($sort, $order);

            $total = $query->count();
            $offset = $request->get('offset', 0);
            $limit = $request->get('limit', 10);

            $result = $query->skip($offset)->take($limit)->get();

            $rows = [];
            $no = $offset + 1;
            foreach ($result as $row) {
                // Generate action buttons using BootstrapTableService
                $operate = BootstrapTableService::button(
                    'fa fa-eye',
                    route('admin.certificates.show', $row->id),
                    ['btn-info'],
                    ['title' => 'View'],
                );
                $operate .= BootstrapTableService::editButton(
                    route('admin.certificates.edit', $row->id),
                    false,
                    null,
                    null,
                    null,
                    'fa fa-edit',
                );
                $operate .= BootstrapTableService::button(
                    'fa fa-search',
                    route('admin.certificates.preview', $row->id),
                    ['btn-secondary'],
                    ['title' => 'Preview', 'target' => '_blank'],
                );
                $operate .= BootstrapTableService::button(
                    'fa fa-edit',
                    route('admin.certificates.editor', $row->id),
                    ['btn-primary'],
                    ['title' => 'Edit Design'],
                );
                $operate .= BootstrapTableService::deleteButton(
                    route('admin.certificates.destroy', $row->id),
                    null,
                    $row->id,
                );

                $rows[] = [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'type' => (string) $row->type,
                    'type_display' => ucwords(str_replace('_', ' ', $row->type)),
                    'title' => (string) ($row->title ?? 'N/A'),
                    'is_active' => (int) $row->is_active,
                    'is_active_display' => $row->is_active ? 'Active' : 'Inactive',
                    'created_at' => (string) $row->created_at->format('M d, Y'),
                    'created_at_raw' => (string) $row->created_at,
                    'no' => (int) $no++,
                    'operate' => $operate,
                ];
            }

            return response()->json([
                'total' => $total,
                'rows' => $rows,
            ]);
        }

        // For regular GET requests, return the view
        return view('admin.certificates.index', ['type_menu' => 'certificates']);
    }

    /**
     * Show the form for creating a new certificate
     */
    public function create()
    {
        return view('admin.certificates.create', ['type_menu' => 'certificates']);
    }

    /**
     * Store a newly created certificate
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:course_completion,exam_completion,custom',
            'background_image' => [
                'nullable',
                'file',
                'mimes:jpeg,png,jpg,gif,svg,webp',
                'max:2048',
                static function ($attribute, $value, $fail): void {
                    if ($value && !in_array(strtolower((string) $value->getClientOriginalExtension()), [
                        'jpeg',
                        'jpg',
                        'png',
                        'gif',
                        'svg',
                        'webp',
                    ])) {
                        $fail('The background image must be a valid image file (jpeg, png, jpg, gif, svg, or webp).');
                    }
                },
            ],
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:500',
            'signature_image' => [
                'nullable',
                'file',
                'mimes:jpeg,png,jpg,gif,svg,webp',
                'max:1024',
                static function ($attribute, $value, $fail): void {
                    if ($value && !in_array(strtolower((string) $value->getClientOriginalExtension()), [
                        'jpeg',
                        'jpg',
                        'png',
                        'gif',
                        'svg',
                        'webp',
                    ])) {
                        $fail('The signature image must be a valid image file (jpeg, png, jpg, gif, svg, or webp).');
                    }
                },
            ],
            'signature_text' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        // Check for duplicate certificate type
        $existingCertificate = Certificate::where('type', $request->type)->first();
        if ($existingCertificate) {
            $typeDisplay = ucwords(str_replace('_', ' ', $request->type));
            if ($request->ajax() || $request->wantsJson()) {
                ResponseService::validationError(
                    "A certificate of type '{$typeDisplay}' already exists. Only one certificate per type is allowed.",
                );
            }
            return redirect()
                ->back()
                ->withErrors([
                    'type' => "A certificate of type '{$typeDisplay}' already exists. Only one certificate per type is allowed.",
                ])
                ->withInput();
        }

        try {
            $data = $request->all();
            $data['is_active'] = $request->has('is_active');

            // Handle background image upload
            if ($request->hasFile('background_image')) {
                $backgroundImage = $request->file('background_image');
                $backgroundImageName =
                    'certificate_bg_' . time() . '.' . $backgroundImage->getClientOriginalExtension();
                $backgroundImagePath = $backgroundImage->storeAs(
                    'certificates/backgrounds',
                    $backgroundImageName,
                    'public',
                );
                $data['background_image'] = $backgroundImagePath;
            }

            // Handle signature image upload
            if ($request->hasFile('signature_image')) {
                $signatureImage = $request->file('signature_image');
                $signatureImageName =
                    'certificate_signature_' . time() . '.' . $signatureImage->getClientOriginalExtension();
                $signatureImagePath = $signatureImage->storeAs(
                    'certificates/signatures',
                    $signatureImageName,
                    'public',
                );
                $data['signature_image'] = $signatureImagePath;
            }

            // Handle template settings
            $templateSettings = [];
            if ($request->has('template_settings')) {
                $templateSettings = is_string($request->template_settings)
                    ? json_decode($request->template_settings, true)
                    : $request->template_settings;
            }
            $data['template_settings'] = $templateSettings;

            Certificate::create($data);

            return ResponseService::successResponse('Certificate created successfully', null, [
                'success' => true,
                'redirect_url' => route('admin.certificates.index'),
            ]);
        } catch (QueryException $e) {
            // Handle database integrity constraint violations
            if (in_array($e->getCode(), ['23000', '1062'])) {
                $typeDisplay = ucwords(str_replace('_', ' ', $request->type ?? ''));
                if ($request->ajax() || $request->wantsJson()) {
                    ResponseService::validationError(
                        "A certificate of type '{$typeDisplay}' already exists. Only one certificate per type is allowed.",
                    );
                }
                return redirect()
                    ->back()
                    ->withErrors([
                        'type' => "A certificate of type '{$typeDisplay}' already exists. Only one certificate per type is allowed.",
                    ])
                    ->withInput();
            }
            return ResponseService::errorResponse('Failed to create certificate: ' . $e->getMessage());
        } catch (\Exception $e) {
            return ResponseService::errorResponse('Failed to create certificate: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified certificate
     */
    public function show(Certificate $certificate)
    {
        return view('admin.certificates.show', compact('certificate'), ['type_menu' => 'certificates']);
    }

    /**
     * Show the form for editing the certificate
     */
    public function edit(Certificate $certificate)
    {
        return view('admin.certificates.edit', compact('certificate'), ['type_menu' => 'certificates']);
    }

    /**
     * Update the specified certificate
     */
    public function update(Request $request, Certificate $certificate)
    {
        // Handle template_settings if it's sent as a JSON string
        if ($request->has('template_settings') && is_string($request->template_settings)) {
            $decodedSettings = json_decode($request->template_settings, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge(['template_settings' => $decodedSettings]);
            }
        }

        // Different validation rules for AJAX requests (design updates)
        if (request()->ajax()) {
            $request->validate([
                'name' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'type' => 'nullable|in:course_completion,exam_completion,custom',
                'background_image' => [
                    'nullable',
                    'file',
                    'mimes:jpeg,png,jpg,gif,svg,webp',
                    'max:2048',
                    static function ($attribute, $value, $fail): void {
                        if ($value && !in_array(strtolower((string) $value->getClientOriginalExtension()), [
                            'jpeg',
                            'jpg',
                            'png',
                            'gif',
                            'svg',
                            'webp',
                        ])) {
                            $fail(
                                'The background image must be a valid image file (jpeg, png, jpg, gif, svg, or webp).',
                            );
                        }
                    },
                ],
                'title' => 'nullable|string|max:255',
                'subtitle' => 'nullable|string|max:500',
                'signature_image' => [
                    'nullable',
                    'file',
                    'mimes:jpeg,png,jpg,gif,svg,webp',
                    'max:1024',
                    static function ($attribute, $value, $fail): void {
                        if ($value && !in_array(strtolower((string) $value->getClientOriginalExtension()), [
                            'jpeg',
                            'jpg',
                            'png',
                            'gif',
                            'svg',
                            'webp',
                        ])) {
                            $fail(
                                'The signature image must be a valid image file (jpeg, png, jpg, gif, svg, or webp).',
                            );
                        }
                    },
                ],
                'signature_text' => 'nullable|string|max:255',
                'template_settings' => 'nullable|array',
                'is_active' => 'boolean',
            ]);
        } else {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'type' => 'required|in:course_completion,exam_completion,custom',
                'background_image' => [
                    'nullable',
                    'file',
                    'mimes:jpeg,png,jpg,gif,svg,webp',
                    'max:2048',
                    static function ($attribute, $value, $fail): void {
                        if ($value && !in_array(strtolower((string) $value->getClientOriginalExtension()), [
                            'jpeg',
                            'jpg',
                            'png',
                            'gif',
                            'svg',
                            'webp',
                        ])) {
                            $fail(
                                'The background image must be a valid image file (jpeg, png, jpg, gif, svg, or webp).',
                            );
                        }
                    },
                ],
                'title' => 'nullable|string|max:255',
                'subtitle' => 'nullable|string|max:500',
                'signature_image' => [
                    'nullable',
                    'file',
                    'mimes:jpeg,png,jpg,gif,svg,webp',
                    'max:1024',
                    static function ($attribute, $value, $fail): void {
                        if ($value && !in_array(strtolower((string) $value->getClientOriginalExtension()), [
                            'jpeg',
                            'jpg',
                            'png',
                            'gif',
                            'svg',
                            'webp',
                        ])) {
                            $fail(
                                'The signature image must be a valid image file (jpeg, png, jpg, gif, svg, or webp).',
                            );
                        }
                    },
                ],
                'signature_text' => 'nullable|string|max:255',
                'is_active' => 'boolean',
            ]);
        }

        // Check for duplicate certificate type (excluding current record)
        if ($certificate->type != $request->type) {
            $existingCertificate = Certificate::where('type', $request->type)
                ->where('id', '!=', $certificate->id)
                ->first();

            if ($existingCertificate) {
                $typeDisplay = ucwords(str_replace('_', ' ', $request->type));
                if ($request->ajax() || $request->wantsJson()) {
                    ResponseService::validationError(
                        "A certificate of type '{$typeDisplay}' already exists. Only one certificate per type is allowed.",
                    );
                }
                return redirect()
                    ->back()
                    ->withErrors([
                        'type' => "A certificate of type '{$typeDisplay}' already exists. Only one certificate per type is allowed.",
                    ])
                    ->withInput();
            }
        }

        try {
            $data = $request->all();
            $data['is_active'] = $request->has('is_active');

            // Handle background image upload
            if ($request->hasFile('background_image')) {
                // Delete old background image
                if ($certificate->background_image) {
                    Storage::disk('public')->delete($certificate->background_image);
                }

                $backgroundImage = $request->file('background_image');
                $backgroundImageName =
                    'certificate_bg_' . time() . '.' . $backgroundImage->getClientOriginalExtension();
                $backgroundImagePath = $backgroundImage->storeAs(
                    'certificates/backgrounds',
                    $backgroundImageName,
                    'public',
                );
                $data['background_image'] = $backgroundImagePath;
            }

            // Handle signature image upload
            if ($request->hasFile('signature_image')) {
                // Delete old signature image
                if ($certificate->signature_image) {
                    Storage::disk('public')->delete($certificate->signature_image);
                }

                $signatureImage = $request->file('signature_image');
                $signatureImageName =
                    'certificate_signature_' . time() . '.' . $signatureImage->getClientOriginalExtension();
                $signatureImagePath = $signatureImage->storeAs(
                    'certificates/signatures',
                    $signatureImageName,
                    'public',
                );
                $data['signature_image'] = $signatureImagePath;
            }

            // Handle template settings
            $templateSettings = [];
            if ($request->has('template_settings')) {
                $templateSettings = is_string($request->template_settings)
                    ? json_decode($request->template_settings, true)
                    : $request->template_settings;
            }
            $data['template_settings'] = $templateSettings;

            $certificate->update($data);

            // Check if this is an AJAX request
            if (request()->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Certificate updated successfully',
                    'redirect_url' => route('admin.certificates.index'),
                    'data' => $certificate,
                ]);
            }

            return ResponseService::successResponse('Certificate updated successfully', null, [
                'success' => true,
                'redirect_url' => route('admin.certificates.index'),
            ]);
        } catch (QueryException $e) {
            // Handle database integrity constraint violations
            if (in_array($e->getCode(), ['23000', '1062'])) {
                $typeDisplay = ucwords(str_replace('_', ' ', $request->type ?? ''));
                if ($request->ajax() || $request->wantsJson()) {
                    ResponseService::validationError(
                        "A certificate of type '{$typeDisplay}' already exists. Only one certificate per type is allowed.",
                    );
                }
                return redirect()
                    ->back()
                    ->withErrors([
                        'type' => "A certificate of type '{$typeDisplay}' already exists. Only one certificate per type is allowed.",
                    ])
                    ->withInput();
            }
            // Check if this is an AJAX request
            if (request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update certificate: ' . $e->getMessage(),
                    'error' => true,
                ], 500);
            }
            return ResponseService::errorResponse('Failed to update certificate: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Check if this is an AJAX request
            if (request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update certificate: ' . $e->getMessage(),
                    'error' => true,
                ], 500);
            }

            return ResponseService::errorResponse('Failed to update certificate: ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified certificate
     */
    public function destroy(Certificate $certificate)
    {
        try {
            // Delete associated images
            if ($certificate->background_image) {
                Storage::disk('public')->delete($certificate->background_image);
            }
            if ($certificate->signature_image) {
                Storage::disk('public')->delete($certificate->signature_image);
            }

            $certificate->delete();

            return ResponseService::successResponse('Certificate deleted successfully');
        } catch (\Exception $e) {
            return ResponseService::errorResponse('Failed to delete certificate: ' . $e->getMessage());
        }
    }

    /**
     * Toggle certificate status
     */
    public function toggleStatus(Certificate $certificate)
    {
        try {
            $certificate->update(['is_active' => !$certificate->is_active]);

            $status = $certificate->is_active ? 'activated' : 'deactivated';
            return ResponseService::successResponse("Certificate {$status} successfully");
        } catch (\Exception $e) {
            return ResponseService::errorResponse('Failed to update certificate status: ' . $e->getMessage());
        }
    }

    /**
     * Preview certificate
     */
    public function preview(Certificate $certificate)
    {
        return view('admin.certificates.preview', compact('certificate'), ['type_menu' => 'certificates']);
    }

    /**
     * Show certificate editor
     */
    public function editor(Certificate $certificate)
    {
        return view('admin.certificates.certificate-editor', compact('certificate'), ['type_menu' => 'certificates']);
    }

    /**
     * Update certificate design/template settings
     */
    public function updateDesign(Request $request, Certificate $certificate)
    {
        // Handle template_settings if it's sent as a JSON string
        if ($request->has('template_settings') && is_string($request->template_settings)) {
            $decodedSettings = json_decode($request->template_settings, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge(['template_settings' => $decodedSettings]);
            }
        }

        $request->validate([
            'template_settings' => 'nullable',
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:500',
            'signature_text' => 'nullable|string|max:255',
            'background_image' => [
                'nullable',
                'file',
                'mimes:jpeg,png,jpg,gif,svg,webp',
                'max:2048',
                static function ($attribute, $value, $fail): void {
                    if ($value && !in_array(strtolower((string) $value->getClientOriginalExtension()), [
                        'jpeg',
                        'jpg',
                        'png',
                        'gif',
                        'svg',
                        'webp',
                    ])) {
                        $fail('The background image must be a valid image file (jpeg, png, jpg, gif, svg, or webp).');
                    }
                },
            ],
            'signature_image' => [
                'nullable',
                'file',
                'mimes:jpeg,png,jpg,gif,svg,webp',
                'max:1024',
                static function ($attribute, $value, $fail): void {
                    if ($value && !in_array(strtolower((string) $value->getClientOriginalExtension()), [
                        'jpeg',
                        'jpg',
                        'png',
                        'gif',
                        'svg',
                        'webp',
                    ])) {
                        $fail('The signature image must be a valid image file (jpeg, png, jpg, gif, svg, or webp).');
                    }
                },
            ],
        ]);

        try {
            $data = $request->only([
                'template_settings',
                'title',
                'subtitle',
                'signature_text',
            ]);

            // Handle background image upload
            if ($request->hasFile('background_image')) {
                // Delete old background image
                if ($certificate->background_image) {
                    Storage::disk('public')->delete($certificate->background_image);
                }

                $backgroundImage = $request->file('background_image');
                $backgroundImageName =
                    'certificate_bg_' . time() . '.' . $backgroundImage->getClientOriginalExtension();
                $backgroundImagePath = $backgroundImage->storeAs(
                    'certificates/backgrounds',
                    $backgroundImageName,
                    'public',
                );
                $data['background_image'] = $backgroundImagePath;
            }

            // Handle signature image upload
            if ($request->hasFile('signature_image')) {
                // Delete old signature image
                if ($certificate->signature_image) {
                    Storage::disk('public')->delete($certificate->signature_image);
                }

                $signatureImage = $request->file('signature_image');
                $signatureImageName =
                    'certificate_signature_' . time() . '.' . $signatureImage->getClientOriginalExtension();
                $signatureImagePath = $signatureImage->storeAs(
                    'certificates/signatures',
                    $signatureImageName,
                    'public',
                );
                $data['signature_image'] = $signatureImagePath;
            }

            // Handle template settings
            if ($request->has('template_settings')) {
                if ($request->template_settings === null) {
                    // Clear template_settings (reset design)
                    $data['template_settings'] = null;
                } else {
                    $templateSettings = is_string($request->template_settings)
                        ? json_decode($request->template_settings, true)
                        : $request->template_settings;
                    $data['template_settings'] = $templateSettings;
                }
            }

            $certificate->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Certificate design updated successfully',
                'data' => $certificate,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update certificate design: ' . $e->getMessage(),
                'error' => true,
            ], 500);
        }
    }
}
