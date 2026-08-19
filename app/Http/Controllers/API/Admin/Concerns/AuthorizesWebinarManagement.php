<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin\Concerns;

use App\Models\Webinar;
use Illuminate\Support\Facades\Auth;

trait AuthorizesWebinarManagement
{
    /**
     * Allow Super Admin / staff / supervisor / instructor to manage webinars.
     * Instructors are scoped to their own webinars by caller ownership checks.
     */
    protected function ensureWebinarManager(): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->unauthorized('Unauthenticated');
        }

        $roles = array_values(array_filter([
            'Super Admin',
            'Instructor',
            config('constants.SYSTEM_ROLES.SUPER_ADMIN'),
            config('constants.SYSTEM_ROLES.STAFF'),
            config('constants.SYSTEM_ROLES.SUPERVISOR'),
            config('constants.SYSTEM_ROLES.INSTRUCTOR'),
            config('constants.SYSTEM_ROLES.TEAM_INSTRUCTOR'),
        ]));

        if (!$user->hasAnyRole($roles) && !$user->hasAnyRole($roles, 'web')) {
            $this->unauthorized('Admin access required');
        }
    }

    protected function isInstructorScoped(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return true;
        }

        $isElevated = $user->hasRole('Super Admin')
            || $user->hasRole(config('constants.SYSTEM_ROLES.SUPER_ADMIN'))
            || $user->hasRole(config('constants.SYSTEM_ROLES.STAFF'))
            || $user->hasRole(config('constants.SYSTEM_ROLES.SUPERVISOR'));

        return !$isElevated;
    }

    protected function ensureCanManageWebinar(Webinar $webinar): ?\Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return $this->jsonError('Unauthorized', 403);
        }

        $isInstructorOnly = $user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))
            || $user->hasRole('Instructor');
        $isElevated = $user->hasRole('Super Admin')
            || $user->hasRole(config('constants.SYSTEM_ROLES.SUPER_ADMIN'))
            || $user->hasRole(config('constants.SYSTEM_ROLES.STAFF'))
            || $user->hasRole(config('constants.SYSTEM_ROLES.SUPERVISOR'));

        if ($isInstructorOnly && !$isElevated && (int) $webinar->instructor_id !== (int) $user->id) {
            return $this->jsonError('Unauthorized', 403);
        }

        return null;
    }
}
