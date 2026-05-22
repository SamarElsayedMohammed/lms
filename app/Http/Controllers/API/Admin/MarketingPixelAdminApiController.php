<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\MarketingPixel;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

final class MarketingPixelAdminApiController extends AdminCrudApiController
{
    private const ALLOWED_PLATFORMS = [
        'hotjar', 'microsoft_clarity', 'google_tag_manager',
        'google_analytics', 'facebook', 'tiktok', 'snapchat', 'instagram',
    ];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * List all marketing pixels
     */
    public function index(): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-list');

        $pixels = MarketingPixel::orderBy('platform')->orderBy('id')->get()->map(fn($pixel) => [
            'id' => $pixel->id,
            'name' => $pixel->name,
            'platform' => $pixel->platform,
            'pixel_id' => $pixel->pixel_id,
            'is_active' => $pixel->is_active,
            'additional_config' => $pixel->additional_config,
            'created_at' => $pixel->created_at->format('Y-m-d H:i:s'),
        ]);

        return ApiResponseService::successResponse('Marketing pixels retrieved', [
            'pixels' => $pixels,
            'allowed_platforms' => self::ALLOWED_PLATFORMS,
        ]);
    }

    /**
     * Create a marketing pixel (multiple pixels per platform allowed)
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-edit');

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'platform' => 'required|string|in:' . implode(',', self::ALLOWED_PLATFORMS),
            'pixel_id' => 'required|string|max:500',
            'is_active' => 'nullable|boolean',
            'additional_config' => 'nullable|array',
            'additional_config.access_token' => 'nullable|string',
            'additional_config.api_secret' => 'nullable|string',
            'additional_config.test_event_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $pixel = MarketingPixel::create([
            'name' => $request->input('name', $request->platform),
            'platform' => $request->platform,
            'pixel_id' => $request->pixel_id,
            'is_active' => $request->boolean('is_active', true),
            'additional_config' => $request->additional_config,
        ]);

        Cache::forget('marketing_pixels_active');

        return ApiResponseService::successResponse('Marketing pixel created successfully', [
            'pixel' => $this->formatPixel($pixel),
        ], 201);
    }

    /**
     * Update a marketing pixel
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-edit');

        $pixel = MarketingPixel::find($id);
        if (!$pixel) {
            return ApiResponseService::errorResponse('Marketing pixel not found', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'pixel_id' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'additional_config' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        if ($request->has('name')) {
            $pixel->name = $request->name;
        }
        if ($request->has('pixel_id')) {
            $pixel->pixel_id = $request->pixel_id;
        }
        if ($request->has('is_active')) {
            $pixel->is_active = $request->boolean('is_active');
        }
        if ($request->has('additional_config')) {
            $pixel->additional_config = $request->additional_config;
        }

        $pixel->save();

        Cache::forget('marketing_pixels_active');

        return ApiResponseService::successResponse('Marketing pixel updated', [
            'pixel' => $this->formatPixel($pixel),
        ]);
    }

    /**
     * Delete a marketing pixel
     */
    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('settings-system-edit');

        $pixel = MarketingPixel::find($id);
        if (!$pixel) {
            return ApiResponseService::errorResponse('Marketing pixel not found', [], 404);
        }

        $pixel->delete();

        Cache::forget('marketing_pixels_active');

        return ApiResponseService::successResponse('Marketing pixel deleted');
    }

    private function formatPixel(MarketingPixel $pixel): array
    {
        return [
            'id' => $pixel->id,
            'name' => $pixel->name,
            'platform' => $pixel->platform,
            'pixel_id' => $pixel->pixel_id,
            'is_active' => $pixel->is_active,
            'additional_config' => $pixel->additional_config,
        ];
    }
}
