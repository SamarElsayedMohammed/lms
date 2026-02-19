<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeatureFlagController extends Controller
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService
    ) {
        $this->middleware('permission:feature-flags-list')->only('index');
        $this->middleware('permission:feature-flags-edit')->only('toggle');
    }

    /**
     * Display feature flags management page.
     */
    public function index(): View
    {
        $flags = $this->featureFlagService->getAll();

        return view('admin.feature-flags.index', compact('flags'));
    }

    /**
     * Toggle a feature flag.
     */
    public function toggle(int $id): JsonResponse
    {
        $flag = FeatureFlag::find($id);

        if ($flag === null) {
            return response()->json([
                'error' => true,
                'message' => 'Feature flag not found',
                'code' => 404,
            ], 404);
        }

        $flag->is_enabled = !$flag->is_enabled;
        $flag->save();

        $this->featureFlagService->clearCache($flag->key);

        return response()->json([
            'error' => false,
            'message' => 'Feature flag updated',
            'data' => [
                'id' => $flag->id,
                'is_enabled' => $flag->is_enabled,
            ],
            'code' => 200,
        ]);
    }
}
