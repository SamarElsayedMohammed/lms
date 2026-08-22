<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Course\UserCourseTrack;
use App\Models\CustomFormField;
use App\Models\CustomFormFieldOption;
use App\Models\Faq;
use App\Models\Instructor;
use App\Models\InstructorOtherDetail;
use App\Models\InstructorPersonalDetail;
use App\Models\InstructorSocialMedia;
use App\Models\Language;
use App\Models\Page;
use App\Models\PaymentTransaction;
use App\Models\SeoSetting;
use App\Models\SocialLogin;
use App\Models\SocialMedia;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserFcmToken;
use App\Services\ApiResponseService;
use App\Services\ApiService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\Payment\PaymentService;
use App\Support\RoleManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

trait ServesApiSessions
{
    /**
     * Format user response with roles and permissions correctly (removes pivot data).
     *
     * @param User $user
     * @param string $token
     * @return array
     */
    protected function formatUserWithRolesAndPermissions(User $user, string $token, ?string $refreshToken = null): array
    {
        $userData          = $user->toArray();
        $userData['token'] = $token;

        // Include the refresh_token if one was issued (login / registration flows)
        if ($refreshToken !== null) {
            $userData['refresh_token'] = $refreshToken;
            $userData['expires_in']    = config('sanctum.access_token_lifetime', 60) * 60; // seconds
        }

        $userData['roles'] = $user->roles->map(function ($role) {
            return [
                'id'          => $role->id,
                'name'        => $role->name,
                'guard_name'  => $role->guard_name,
                'custom_role' => $role->custom_role,
            ];
        })->toArray();

        $userData['permissions'] = $user->getAllPermissions()->map(function ($permission) {
            return [
                'id'   => $permission->id,
                'name' => $permission->name,
            ];
        })->toArray();

        return $userData;
    }

    public function getActiveSessions(Request $request)
    {
        try {
            $user = Auth::user();
            $currentTokenId = $user->currentAccessToken()?->id;
            $sessions = $user->tokens()
                ->where('name', 'not like', '%-refresh')
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->latest('last_used_at')
                ->get()
                ->map(function ($token) use ($currentTokenId) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'ip_address' => $token->ip_address,
                    'user_agent' => $token->user_agent,
                    'last_used_at' => $token->last_used_at,
                    'is_current' => (int) $token->id === (int) $currentTokenId,
                ];
            });

            return ApiResponseService::successResponse('Active sessions retrieved successfully', $sessions);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    public function logoutSession(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $token = $user->tokens()->where('id', $id)->first();
            
            if (!$token) {
                return ApiResponseService::errorResponse('Session not found', null, 404);
            }

            $tokenName = $token->name ?? '';
            $baseName = str_replace('-refresh', '', $tokenName);

            DB::beginTransaction();

            // Revoke current token and its paired access/refresh token
            if (!empty($baseName)) {
                $user->tokens()->where(function ($q) use ($baseName, $token) {
                    $q->where('id', $token->id)
                      ->orWhere('name', $baseName)
                      ->orWhere('name', $baseName . '-refresh');
                })->delete();

                // Prune corresponding active device entry
                \App\Models\UserDevice::where('user_id', $user->id)
                    ->where('device_id', $baseName)
                    ->delete();
            } else {
                $token->delete();
            }

            DB::commit();

            return ApiResponseService::successResponse('Session logged out successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    public function userLogout(Request $request)
    {
        try {
            $user = $request->user();
            if ($user) {
                $currentToken = $user->currentAccessToken();
                $tokenName = $currentToken?->name ?? '';
                $deviceId = $request->header('X-Device-Id')
                    ?: $request->input('device_id');

                if (empty($deviceId) && !empty($tokenName)) {
                    $deviceId = str_replace('-refresh', '', $tokenName);
                }

                DB::beginTransaction();

                // Revoke current access token and its paired refresh token
                if ($currentToken) {
                    if (!empty($tokenName)) {
                        $baseName = str_replace('-refresh', '', $tokenName);
                        $user->tokens()->where(function ($q) use ($baseName, $currentToken) {
                            $q->where('id', $currentToken->id)
                              ->orWhere('name', $baseName)
                              ->orWhere('name', $baseName . '-refresh');
                        })->delete();
                    } else {
                        $currentToken->delete();
                    }
                }

                // Prune the active device entry from user_devices table
                if (!empty($deviceId)) {
                    \App\Models\UserDevice::where('user_id', $user->id)
                        ->where('device_id', $deviceId)
                        ->delete();
                } else {
                    $deviceType = $request->input('device_type');
                    if (!empty($deviceType)) {
                        \App\Models\UserDevice::where('user_id', $user->id)
                            ->where('device_type', $deviceType)
                            ->delete();
                    }
                }

                DB::commit();

                if (Auth::guard('web')->check()) {
                    Auth::guard('web')->logout();
                }
            }

            return ApiResponseService::successResponse('تم تسجيل الخروج بنجاح');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    public function userLogoutOthers(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return ApiResponseService::unauthorizedResponse();
            }

            $currentToken = $user->currentAccessToken();
            $currentTokenId = $currentToken?->id;
            $tokenName = $currentToken?->name ?? '';
            $currentDeviceId = $request->header('X-Device-Id')
                ?: $request->input('device_id')
                ?: str_replace('-refresh', '', $tokenName);

            DB::beginTransaction();

            // Revoke all tokens for this user EXCEPT current token and its paired refresh token
            $baseName = str_replace('-refresh', '', $tokenName);
            $tokensQuery = $user->tokens();
            if ($currentTokenId) {
                $tokensQuery->where('id', '!=', $currentTokenId);
            }
            if (!empty($baseName)) {
                $tokensQuery->where('name', '!=', $baseName)
                            ->where('name', '!=', $baseName . '-refresh');
            }
            $tokensQuery->delete();

            // Delete all other user_devices EXCEPT current device
            if (!empty($currentDeviceId)) {
                \App\Models\UserDevice::where('user_id', $user->id)
                    ->where('device_id', '!=', $currentDeviceId)
                    ->delete();
            }

            DB::commit();

            return ApiResponseService::successResponse('تم تسجيل الخروج من كافة الأجهزة الأخرى بنجاح');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    public function getNotificationSettings(Request $request)
    {
        try {
            $user = Auth::user();
            $keys = [
                'course_updates',
                'marketing',
                'wallet_activity',
                'new_messages',
                'promotions',
                'new_courses',
                'security_alerts',
            ];

            foreach ($keys as $key) {
                \App\Models\NotificationSetting::firstOrCreate(
                    ['user_id' => $user->id, 'setting_key' => $key],
                    [
                        'user_id' => $user->id,
                        'setting_key' => $key,
                        'email_enabled' => true,
                        'push_enabled' => true,
                    ]
                );
            }

            $settings = \App\Models\NotificationSetting::where('user_id', $user->id)
                ->whereIn('setting_key', $keys)
                ->get()
                ->sortBy(static fn ($setting) => array_search($setting->setting_key, $keys, true))
                ->values();

            return ApiResponseService::successResponse('Notification settings retrieved successfully', $settings);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    public function updateNotificationSettings(Request $request)
    {
        try {
            $user = Auth::user();
            $validator = Validator::make($request->all(), [
                'settings' => 'required|array',
                'settings.*.setting_key' => [
                    'required',
                    'string',
                    Rule::in([
                        'course_updates',
                        'marketing',
                        'wallet_activity',
                        'new_messages',
                        'promotions',
                        'new_courses',
                        'security_alerts',
                    ]),
                ],
                'settings.*.email_enabled' => 'required|boolean',
                'settings.*.push_enabled' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            foreach ($request->settings as $setting) {
                \App\Models\NotificationSetting::updateOrCreate(
                    ['user_id' => $user->id, 'setting_key' => $setting['setting_key']],
                    [
                        'email_enabled' => $setting['email_enabled'],
                        'push_enabled' => $setting['push_enabled'],
                    ]
                );
            }

            return ApiResponseService::successResponse('Notification settings updated successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    /**
     * Get user's notifications
     */
    public function getMyNotifications(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('يجب تسجيل الدخول.', null, 401);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->query(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $perPage = (int) $request->input('per_page', 15);
        
        $notifications = \App\Models\UserNotification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($notifications->items())->map(static fn ($notification) => [
                ...$notification->toArray(),
                'time_ago' => $notification->created_at?->diffForHumans(),
            ])->values(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'unread_count' => \App\Models\UserNotification::where('user_id', $user->id)
                    ->where('is_read', false)
                    ->count(),
            ]
        ]);
    }

    /**
     * Get user's unread notifications count
     */
    public function getMyUnreadNotificationsCount(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('يجب تسجيل الدخول.', null, 401);
        }

        $count = \App\Models\UserNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $count,
                'count' => $count,
            ]
        ]);
    }

    /**
     * Mark a single notification as read
     */
    public function markMyNotificationRead(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('يجب تسجيل الدخول.', null, 401);
        }

        $notification = \App\Models\UserNotification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return ApiResponseService::errorResponse('الإشعار غير موجود.', null, 404);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllMyNotificationsRead(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('يجب تسجيل الدخول.', null, 401);
        }

        \App\Models\UserNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }
}
