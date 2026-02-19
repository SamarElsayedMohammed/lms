<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\MarketingPixel;
use App\Services\ApiResponseService;
use Illuminate\Support\Facades\Cache;

final class MarketingPixelApiController extends Controller
{
    private const CACHE_KEY = 'marketing_pixels_active';
    private const CACHE_TTL = 60;

    public function getActivePixels()
    {
        $pixels = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return MarketingPixel::active()
                ->get(['platform', 'pixel_id', 'additional_config'])
                ->toArray();
        });
        return ApiResponseService::successResponse('Active pixels retrieved', ['pixels' => $pixels]);
    }
}
