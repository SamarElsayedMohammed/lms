<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

abstract class AdminCrudApiController extends Controller
{
    /**
     * Check admin access - user must have one of these roles
     */
    protected function ensureAdmin(): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->unauthorized('Unauthenticated');
        }
        $adminRoles = ['Super Admin', config('constants.SYSTEM_ROLES.ADMIN'), config('constants.SYSTEM_ROLES.STAFF'), config('constants.SYSTEM_ROLES.SUPERVISOR')];
        if (!$user->hasAnyRole($adminRoles)) {
            $this->unauthorized('Admin access required');
        }
    }

    protected function unauthorized(string $message = 'Unauthorized'): never
    {
        abort(403, $message);
    }

    protected function jsonSuccess(string $message, mixed $data = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ], $code, [], JSON_UNESCAPED_UNICODE);
    }

    protected function jsonError(string $message, int $code = 400): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], $code, [], JSON_UNESCAPED_UNICODE);
    }

    protected function checkPermission(string $permission): void
    {
        if (!Auth::user()?->can($permission)) {
            $this->unauthorized(__('You do not have permission to perform this action'));
        }
    }
}
