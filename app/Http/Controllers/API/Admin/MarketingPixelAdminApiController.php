<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingPixel;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

final class MarketingPixelAdminApiController extends Controller
{
    private const ALLOWED_PLATFORMS = [
        'hotjar', 'microsoft_clarity', 'google_tag_manager',
        'google_analytics', 'facebook', 'tiktok', 'snapchat', 'instagram',
    ];

    /**
     * List all marketing pixels
     */
    public function index(): JsonResponse
    {
        $pixels = MarketingPixel::orderBy('platform')->get()->map(fn($pixel) => [
            'id' => $pixel->id,
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
     * Create or update a marketing pixel
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
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

        $pixel = MarketingPixel::updateOrCreate(
            ['platform' => $request->platform],
            [
                'pixel_id' => $request->pixel_id,
                'is_active' => $request->boolean('is_active', true),
                'additional_config' => $request->additional_config,
            ]
        );

        Cache::forget('marketing_pixels_active');

        return ApiResponseService::successResponse('Marketing pixel saved successfully', [
            'pixel' => [
                'id' => $pixel->id,
                'platform' => $pixel->platform,
                'pixel_id' => $pixel->pixel_id,
                'is_active' => $pixel->is_active,
                'additional_config' => $pixel->additional_config,
            ],
        ]);
    }

    /**
     * Update a marketing pixel
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $pixel = MarketingPixel::find($id);
        if (!$pixel) {
            return ApiResponseService::errorResponse('Marketing pixel not found', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'pixel_id' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'additional_config' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
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
            'pixel' => [
                'id' => $pixel->id,
                'platform' => $pixel->platform,
                'pixel_id' => $pixel->pixel_id,
                'is_active' => $pixel->is_active,
                'additional_config' => $pixel->additional_config,
            ],
        ]);
    }

    /**
     * Delete a marketing pixel
     */
    public function destroy(int $id): JsonResponse
    {
        $pixel = MarketingPixel::find($id);
        if (!$pixel) {
            return ApiResponseService::errorResponse('Marketing pixel not found', [], 404);
        }

        $pixel->delete();

        Cache::forget('marketing_pixels_active');

        return ApiResponseService::successResponse('Marketing pixel deleted');
    }
}
