<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\PopupCampaign;
use App\Services\ApiResponseService;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PopupCampaignAdminApiController extends AdminCrudApiController
{
    private $folder = 'popup_campaigns';

    /**
     * List all campaigns
     */
    public function index(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('marketing-list');

        $perPage = min((int) $request->input('per_page', 15), 50);
        $campaigns = PopupCampaign::latest()->paginate($perPage);

        return ApiResponseService::successResponse('Campaigns retrieved', $campaigns);
    }

    /**
     * Store a new campaign
     */
    public function store(Request $request)
    {
        $this->ensureAdmin();
        $this->checkPermission('marketing-create');

        $validator = Validator::make($request->all(), [
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string',
            'image'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'promo_code'     => 'nullable|string|max:100',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_type'  => 'nullable|in:percentage,amount',
            'cta_url'        => 'nullable|string|max:500',
            'cta_text'       => 'nullable|string|max:100',
            'is_active'      => 'boolean',
            'starts_at'      => 'nullable|date',
            'ends_at'        => 'nullable|date|after_or_equal:starts_at',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $request->only([
                'title', 'description', 'promo_code',
                'discount_value', 'discount_type',
                'cta_url', 'cta_text',
                'starts_at', 'ends_at', 'is_active',
            ]);

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndUpload($request->file('image'), $this->folder);
            }

            $campaign = PopupCampaign::create($data);

            return ApiResponseService::successResponse('Campaign created successfully', $campaign);
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to create campaign: ' . $e->getMessage());
        }
    }

    /**
     * Update campaign
     */
    public function update(Request $request, $id)
    {
        $this->ensureAdmin();
        $this->checkPermission('marketing-edit');

        $campaign = PopupCampaign::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title'          => 'sometimes|required|string|max:255',
            'description'    => 'nullable|string',
            'image'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'promo_code'     => 'nullable|string|max:100',
            'discount_value' => 'nullable|numeric|min:0',
            'discount_type'  => 'nullable|in:percentage,amount',
            'cta_url'        => 'nullable|string|max:500',
            'cta_text'       => 'nullable|string|max:100',
            'is_active'      => 'boolean',
            'starts_at'      => 'nullable|date',
            'ends_at'        => 'nullable|date|after_or_equal:starts_at',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $data = $request->only([
                'title', 'description', 'promo_code',
                'discount_value', 'discount_type',
                'cta_url', 'cta_text',
                'starts_at', 'ends_at', 'is_active',
            ]);

            if ($request->hasFile('image')) {
                $data['image'] = FileService::compressAndReplace(
                    $request->file('image'),
                    $this->folder,
                    $campaign->getRawOriginal('image')
                );
            }

            $campaign->update($data);

            return ApiResponseService::successResponse('Campaign updated successfully', $campaign->fresh());
        } catch (\Throwable $e) {
            return ApiResponseService::errorResponse('Failed to update campaign: ' . $e->getMessage());
        }
    }

    /**
     * Delete campaign
     */
    public function destroy($id)
    {
        $this->ensureAdmin();
        $this->checkPermission('marketing-delete');

        $campaign = PopupCampaign::findOrFail($id);

        if ($campaign->image) {
            FileService::delete($campaign->getRawOriginal('image'));
        }

        $campaign->delete();

        return ApiResponseService::successResponse('Campaign deleted successfully');
    }
}
