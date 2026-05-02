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
     * Get the active popup campaign for the frontend.
     *
     * The popup is designed for subscription plan promotions.
     * It returns discount info + promo code string + CTA link directly,
     * without depending on the promo_codes table (which is for courses).
     */
    public function getActiveCampaign(): JsonResponse
    {
        try {
            $campaign = PopupCampaign::where('is_active', true)
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
                    'id'             => $campaign->id,
                    'title'          => $campaign->title,
                    'description'    => $campaign->description,
                    'image'          => $campaign->image ? url($campaign->image) : null,

                    // Subscription discount info
                    'promo_code'     => $campaign->promo_code,      // e.g. "SKILLS026"
                    'discount_value' => $campaign->discount_value,  // e.g. 30.0
                    'discount_type'  => $campaign->discount_type,   // 'percentage' | 'amount'

                    // CTA button
                    'cta_url'        => $campaign->cta_url,         // e.g. "/subscription-plans"
                    'cta_text'       => $campaign->cta_text,        // e.g. "اشترك الآن"

                    // Date range
                    'starts_at'      => $campaign->starts_at?->toDateTimeString(),
                    'ends_at'        => $campaign->ends_at?->toDateTimeString(),
                ],
            ]);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve popup campaign: ' . $e->getMessage());
        }
    }
}
