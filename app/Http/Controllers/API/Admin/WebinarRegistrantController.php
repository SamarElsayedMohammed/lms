<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WebinarRegistrantController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Delete Registrant
     * DELETE /api/admin/webinars/{slug}/registrants?id=X
     */
    public function destroy(Request $request, Webinar $webinar): JsonResponse
    {
        $this->ensureAdmin();

        if (Auth::user()->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            && $webinar->instructor_id !== Auth::id()) {
            return $this->jsonError('Unauthorized', 403);
        }

        $id = $request->query('id');

        if (!$id) {
            return $this->jsonError('Registrant ID is required.', 400);
        }

        $registration = WebinarRegistration::where('id', $id)
            ->where('webinar_id', $webinar->id)
            ->first();

        if (!$registration) {
            return $this->jsonError('Registrant not found for this webinar.', 404);
        }

        $registration->delete();

        return $this->jsonSuccess('Registrant deleted successfully.');
    }
}
