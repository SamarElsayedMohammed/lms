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
     * Get active popup campaign candidates for the frontend.
     *
     * Returns serialized campaign candidates with complete targeting, design,
     * frequency, and timing configuration, preventing newer ineligible campaigns
     * from shadowing older eligible ones (PLI-13, PLI-34, PLI-35, PLI-36).
     */
    public function getActiveCampaign(): JsonResponse
    {
        try {
            $campaigns = PopupCampaign::where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('starts_at')
                          ->orWhere('starts_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('ends_at')
                          ->orWhere('ends_at', '>=', now());
                })
                ->latest()
                ->limit(10)
                ->get();

            if ($campaigns->isEmpty()) {
                return ApiResponseService::successResponse('No active campaign', [
                    'campaign'  => null,
                    'campaigns' => [],
                ]);
            }

            $serialized = $campaigns->map(function (PopupCampaign $campaign) {
                return [
                    'id'               => $campaign->id,
                    'title'            => $campaign->title,
                    'description'      => $campaign->description,
                    'image'            => $campaign->image ? url($campaign->image) : null,

                    // Subscription discount info (Marketing presentation only)
                    'promo_code'       => $campaign->promo_code,
                    'discount_value'   => $campaign->discount_value,
                    'discount_type'    => $campaign->discount_type ?: 'percentage',

                    // CTA button
                    'cta_url'          => $campaign->cta_url,
                    'cta_text'         => $campaign->cta_text,

                    // Date range
                    'starts_at'        => $campaign->starts_at?->toDateTimeString(),
                    'ends_at'          => $campaign->ends_at?->toDateTimeString(),

                    // Design & Appearance
                    'background_color' => $campaign->background_color,
                    'text_color'       => $campaign->text_color,
                    'button_color'     => $campaign->button_color,
                    'template_style'   => $campaign->template_style ?: 'modal',

                    // Targeting & Delivery
                    'target_audience'  => $campaign->target_audience ?: 'all',
                    'device_type'      => $campaign->device_type ?: 'all',
                    'display_pages'    => $campaign->display_pages,
                    'delay_seconds'    => $campaign->delay_seconds ?? 0,
                    'max_impressions'  => $campaign->max_impressions,
                ];
            })->values()->all();

            return ApiResponseService::successResponse('Active campaigns retrieved', [
                'campaign'  => $serialized[0] ?? null,
                'campaigns' => $serialized,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to retrieve popup campaign: ' . $e->getMessage());
        }
    }
}
