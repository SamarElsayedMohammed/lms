<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\AdminAuditLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AdminAuditLogApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'role:Super Admin|Supervisor']);
    }

    protected function ensureAdmin(): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->unauthorized('Unauthenticated');
        }

        $allowed = [
            'Super Admin',
            'Supervisor',
            config('constants.SYSTEM_ROLES.SUPER_ADMIN'),
            config('constants.SYSTEM_ROLES.SUPERVISOR'),
        ];

        if (!$user->hasAnyRole($allowed, 'web')) {
            $this->unauthorized('Admin access required');
        }
    }

    /**
     * List immutable audit logs with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'action'      => 'nullable|string|max:100',
            'user_id'     => 'nullable|integer',
            'target_type' => 'nullable|string|max:100',
            'search'      => 'nullable|string|max:255',
            'from_date'   => 'nullable|date',
            'to_date'     => 'nullable|date|after_or_equal:from_date',
            'per_page'    => 'nullable|integer|min:1|max:100',
            'page'        => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        $perPage = (int) $request->input('per_page', 20);

        $query = AdminAuditLog::query()->with('user:id,name,email');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('target_type')) {
            $query->where('target_type', $request->target_type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('actor_name', 'like', "%{$search}%")
                  ->orWhere('actor_email', 'like', "%{$search}%")
                  ->orWhere('summary', 'like', "%{$search}%")
                  ->orWhere('target_type', 'like', "%{$search}%");
            });
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', Carbon::parse($request->from_date)->startOfDay());
        }

        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', Carbon::parse($request->to_date)->endOfDay());
        }

        $logs = $query->orderByDesc('id')->paginate($perPage);

        return $this->jsonSuccess(__('Audit logs retrieved successfully'), $logs);
    }
}
