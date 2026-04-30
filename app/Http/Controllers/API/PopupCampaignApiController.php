<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PopupCampaign;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;

final class PopupCampaignApiController extends Controller
{
    /**
     * Get the active popup campaign for frontend
     */
    public function getActiveCampaign(): JsonResponse
    {
        try {
            $campaign = PopupCampaign::with('promoCode')
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('starts_at')
                          ->orWhere('starts_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('ends_at')
                          ->orWhere('ends_at', '>=', now());
                })
                ->latest()
                ->first();

            if (!$campaign) {
                return ApiResponseService::successResponse('No active campaign', ['campaign' => null]);
            }

            return ApiResponseService::successResponse('Active campaign retrieved', [
                'campaign' => [
                    'id' => $campaign->id,
                    'title' => $campaign->title,
                    'description' => $campaign->description,
                    'image' => $campaign->image ? url($campaign->image) : null,
                    'promo_code' => $campaign->promoCode ? [
                        'code' => $campaign->promoCode->promo_code,
                        'discount_type' => $campaign->promoCode->discount_type,
                        'discount_amount' => (float) $campaign->promoCode->discount_amount,
                        'minimum_amount' => (float) $campaign->promoCode->minimum_amount,
                    ] : null,
                ]
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve popup campaign: ' . $e->getMessage());
        }
    }
}
