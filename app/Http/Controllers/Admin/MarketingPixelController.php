<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingPixel;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class MarketingPixelController extends Controller
{
    private const PLATFORMS = [
        'hotjar', 'microsoft_clarity', 'google_tag_manager',
        'facebook', 'tiktok', 'snapchat', 'instagram',
    ];

    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['settings-system-list', 'manage_settings']);
        $pixels = MarketingPixel::orderBy('platform')->get();
        return view('admin.marketing-pixels.index', [
            'pixels' => $pixels,
            'platforms' => self::PLATFORMS,
            'type_menu' => 'marketing-pixels',
        ]);
    }

    public function store(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['settings-system-list', 'manage_settings']);
        $validator = Validator::make($request->all(), [
            'platform' => 'required|string|in:' . implode(',', self::PLATFORMS),
            'pixel_id' => 'required|string|max:500',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        MarketingPixel::updateOrCreate(
            ['platform' => $request->platform],
            ['pixel_id' => $request->pixel_id, 'is_active' => $request->boolean('is_active')]
        );
        ResponseService::successResponse('Marketing pixel saved successfully.');
    }

    public function destroy(int $id)
    {
        ResponseService::noAnyPermissionThenRedirect(['settings-system-list', 'manage_settings']);
        MarketingPixel::findOrFail($id)->delete();
        ResponseService::successResponse('Marketing pixel removed.');
    }
}
