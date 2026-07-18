<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ApiResponseService;
use App\Services\AffiliateService;

class AffiliateApiController extends Controller
{
    public function status(Request $request, AffiliateService $affiliateService)
    {
        return ApiResponseService::successResponse('Status retrieved', [
            'enabled' => $affiliateService->isEnabled(),
        ]);
    }

    public function getMyLink(Request $request, AffiliateService $affiliateService)
    {
        if (!$affiliateService->isEnabled()) {
            return ApiResponseService::errorResponse('Affiliate system is disabled');
        }
        $link = $affiliateService->generateAffiliateLink($request->user());
        return ApiResponseService::successResponse('Link retrieved', ['link' => $link]);
    }

    public function getStats(Request $request)
    {
        return ApiResponseService::errorResponse('Not implemented yet', 501);
    }

    public function getCommissions(Request $request)
    {
        return ApiResponseService::errorResponse('Not implemented yet', 501);
    }

    public function requestWithdrawal(Request $request)
    {
        return ApiResponseService::errorResponse('Not implemented yet', 501);
    }

    public function transferToWallet(Request $request)
    {
        return ApiResponseService::errorResponse('Not implemented yet', 501);
    }

    public function getWithdrawals(Request $request)
    {
        return ApiResponseService::errorResponse('Not implemented yet', 501);
    }

    public function getReferrals(Request $request)
    {
        return ApiResponseService::errorResponse('Not implemented yet', 501);
    }

    public function getMarketingAssets(Request $request)
    {
        return ApiResponseService::errorResponse('Not implemented yet', 501);
    }

    public function trackReferral(Request $request, $code, AffiliateService $affiliateService)
    {
        if ($affiliateService->isEnabled()) {
            $affiliateService->trackClick($code);
        }
        return ApiResponseService::successResponse('Referral tracked');
    }
}
