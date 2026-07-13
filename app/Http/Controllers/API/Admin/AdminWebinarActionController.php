<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Webinar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AdminWebinarActionController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Update webinar status
     * POST /api/admin/webinars/{slug}/change-status
     */
    public function changeStatus(Request $request, Webinar $webinar): JsonResponse
    {
        $this->ensureAdmin();

        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            && $webinar->instructor_id !== Auth::id()) {
            return $this->jsonError('Unauthorized', 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,scheduled,published,active,live,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $webinar->update(['status' => $request->status]);

        return $this->jsonSuccess('Webinar status updated to ' . $request->status, $webinar->fresh());
    }

    /**
     * Toggle publish status on a webinar (publish/unpublish)
     * POST /api/admin/webinars/{slug}/toggle-publish
     */
    public function togglePublish(Webinar $webinar): JsonResponse
    {
        $this->ensureAdmin();

        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            && $webinar->instructor_id !== Auth::id()) {
            return $this->jsonError('Unauthorized', 403);
        }

        $newValue = ! ((bool) $webinar->is_published);
        $webinar->update(['is_published' => $newValue]);

        $msg = $newValue ? 'Webinar published successfully' : 'Webinar unpublished successfully';

        return $this->jsonSuccess($msg, [
            'id'           => $webinar->id,
            'is_published' => $newValue,
        ]);
    }

    /**
     * Set a webinar as the default.
     * POST /api/admin/webinars/{slug}/set-default
     */
    public function setDefault(Webinar $webinar): JsonResponse
    {
        $this->ensureAdmin();

        // Optional: only super admins can set default
        // if (!Auth::user()->hasRole(config('constants.SYSTEM_ROLES.SUPER_ADMIN'))) {
        //     return $this->jsonError('Unauthorized', 403);
        // }

        // Unset all others
        Webinar::where('id', '!=', $webinar->id)->update(['is_featured' => false]);
        
        $webinar->update(['is_featured' => true]);

        return $this->jsonSuccess('Webinar set as default successfully', [
            'id' => $webinar->id,
            'is_featured' => true,
        ]);
    }

    /**
     * Restore a soft deleted webinar
     * PUT /api/admin/webinars/{slug}/restore
     */
    public function restore($slug): JsonResponse
    {
        $this->ensureAdmin();

        $webinar = Webinar::withTrashed()->where('slug', $slug)->first();

        if (!$webinar) {
            return $this->jsonError('Webinar not found', 404);
        }

        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            && $webinar->instructor_id !== Auth::id()) {
            return $this->jsonError('Unauthorized', 403);
        }

        $webinar->restore();

        return $this->jsonSuccess('Webinar restored successfully', $webinar);
    }
}
