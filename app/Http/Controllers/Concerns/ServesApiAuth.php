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
use App\Services\AuditLogService;
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

trait ServesApiAuth
{
    public function version()
    {
        return response()->json([
            'success' => true,
            'app' => 'Skillso Backend',
            'version' => '2.0.0-auth-v2',
            'backend_commit' => trim(@file_get_contents(base_path('COMMIT_SHA')) ?: 'v2-repair'),
            'timestamp' => date('c'),
        ])->header('X-Backend-Commit', 'v2-repair');
    }

    public function debugGeoHeaders(Request $request)
    {
        $countryService = app(\App\Services\CountryDetectionService::class);
        return response()->json($countryService->debug($request));
    }

    public function debugRawRequest(Request $request)
    {
        $geoHeaders = [
            'CF-IPCountry' => $request->header('CF-IPCountry'),
            'X-User-Country' => $request->header('X-User-Country'),
            'X-Vercel-IP-Country' => $request->header('X-Vercel-IP-Country'),
            'X-Country' => $request->header('X-Country'),
            'CF-Connecting-IP' => $request->header('CF-Connecting-IP'),
            'X-Forwarded-For' => $request->header('X-Forwarded-For'),
            'X-Real-IP' => $request->header('X-Real-IP'),
        ];

        $allHeaders = [];
        foreach ($request->headers->all() as $key => $values) {
            $allHeaders[$key] = is_array($values) && count($values) === 1 ? $values[0] : $values;
        }

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'laravel_ip' => $request->ip(),
            'geo_headers' => $geoHeaders,
            'all_headers' => $allHeaders,
        ]);
    }

    public function userExists(Request $request)
    {
        try {
            $this->normalizePhoneRequest($request);
            ApiService::validateRequest($request, [
                'country_calling_code' => 'required_without:email|string',
                'mobile' => 'required_without:email|numeric',
                'email' => 'required_without:mobile|email',
            ]);

            $email = $request->has('email') ? strtolower(trim((string) $request->email)) : null;

            // Check if user exists (including soft-deleted)
            $userQuery = RoleManager::applyRoleFilter(User::query(), 'user')
                ->withTrashed()
                ->when($email !== null && $email !== '', static function ($query) use ($email): void {
                    $query->where('email', $email);
                })
                ->when($request->has('mobile'), static function ($query) use ($request): void {
                    $rawMobile = (string) $request->mobile;
                    $normalizedMobile = preg_replace('/\D+/', '', $rawMobile) ?? '';
                    $trimmedMobile = ltrim($normalizedMobile, '0');
                    $mobileVariants = array_unique(array_filter([
                        $rawMobile,
                        $normalizedMobile,
                        $trimmedMobile,
                        '0' . $trimmedMobile,
                    ]));

                    $rawCode = (string) $request->input('country_calling_code');
                    $normalizedCode = preg_replace('/\D+/', '', $rawCode) ?? '';
                    $codeVariants = array_unique(array_filter([
                        $rawCode,
                        '+' . $normalizedCode,
                        $normalizedCode,
                    ]));

                    $query->whereIn('mobile', $mobileVariants);
                    if (!empty($codeVariants)) {
                        $query->whereIn('country_calling_code', $codeVariants);
                    }
                });

            $user = $userQuery->latest()->first();

            // If user exists but is soft-deleted, treat as new user
            if ($user && $user->trashed()) {
                return ApiResponseService::successResponse(data: ['is_new_user' => true]);
            }

            // If user exists and is not deleted, treat as existing user
            if ($user && !$user->trashed()) {
                return ApiResponseService::successResponse(data: ['is_new_user' => false]);
            }

            // If user doesn't exist at all, treat as new user
            return ApiResponseService::successResponse(data: ['is_new_user' => true]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            DB::rollBack();
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    public function userSignup(Request $request)
    {
        try {
            $this->aliasSocialAuthType($request);
            // Normalize email and confirmation aliases before validation
            if ($request->has('email') && !empty($request->email)) {
                $request->merge(['email' => strtolower(trim((string) $request->email))]);
            }
            if ($request->filled('password_confirmation') && !$request->filled('confirm_password')) {
                $request->merge(['confirm_password' => $request->input('password_confirmation')]);
            }
            if ($request->filled('confirm_password') && !$request->filled('password_confirmation')) {
                $request->merge(['password_confirmation' => $request->input('confirm_password')]);
            }

            // Base validation rules
            // firebase_token is optional for web clients (device_type=web) that use Google OAuth flow
            $isWebOAuth = ($request->device_type === 'web' || empty($request->device_type))
                && in_array($request->type, ['google', 'apple'])
                && empty($request->firebase_token);

            // firebase_token logic:
            //   - google / apple  → always required (Firebase is the identity provider)
            //   - email           → NEVER needed; user authenticates with email + password
            //   - web OAuth path  → optional (handled via Socialite access_token)
            $isEmailType  = ($request->type === 'email');
            $isWebOAuth   = !$isEmailType
                && ($request->device_type === 'web' || empty($request->device_type))
                && in_array($request->type, ['google', 'apple'])
                && empty($request->firebase_token);

            $validationRules = [
                'type'          => 'required|in:google,apple,email',
                'platform_type' => 'nullable|in:android,ios',
                // email-type never sends firebase_token; google/apple always must
                'firebase_token' => ($isEmailType || $isWebOAuth) ? 'nullable|string' : 'required|string',
                'mobile'        => 'nullable|unique:users,mobile',
                'device_type'   => 'nullable|in:web,android,ios,desktop',
                'device_id'     => 'nullable|string|max:255',
                'device_name'   => 'nullable|string|max:255',
                'profile'       => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            ];

            // email-type requires password credentials
            if ($isEmailType) {
                $validationRules['password']         = 'required|string|min:6';
                $validationRules['confirm_password'] = 'required|string|min:6|same:password';
                $validationRules['email']            = 'required|email';
            }

            ApiService::validateRequest($request, $validationRules);

            // ── Resolve Firebase/OAuth/Email identity ────────────────────────
            $firebaseId = null;

            $convertingGuest = null;

            if ($isEmailType) {
                // ── Email / Password path — NO Firebase involved ─────────────
                $existingEmailUser = User::where('email', $request->email)->withTrashed()->first();

                if ($existingEmailUser) {
                    if ($existingEmailUser->trashed() || (isset($existingEmailUser->is_active) && !$existingEmailUser->is_active)) {
                        ApiResponseService::validationError(
                            'تم تعطيل هذا الحساب. يرجى التواصل مع الدعم.',
                        );
                    } elseif ($existingEmailUser->isWebinarGuest()) {
                        // Same email later creating a Skillso account reuses the guest lead.
                        $convertingGuest = $existingEmailUser;
                    } else {
                        ApiResponseService::validationError(
                            'يوجد حساب بهذا البريد الإلكتروني. يرجى تسجيل الدخول.',
                            ['error_code' => 'EMAIL_ALREADY_REGISTERED'],
                        );
                    }
                }

                $firebaseId = null;

            } elseif ($isWebOAuth && $request->type === 'google') {
                // Web Google login: resolve via Socialite (no Firebase needed)
                $accessToken = $request->input('access_token')
                    ?? $request->input('provider_token')
                    ?? $request->input('token');

                if (empty($accessToken)) {
                    ApiResponseService::validationError('رمز دخول Google مطلوب لتسجيل الدخول عبر الويب.');
                }

                try {
                    $socialUser = \Laravel\Socialite\Facades\Socialite::driver('google')->stateless()->userFromToken($accessToken);
                    // Use Google UID as the stable identity key
                    $firebaseId = 'google-oauth-' . $socialUser->getId();

                    // Ensure email is set in request for downstream code
                    if (empty($request->email) && $socialUser->getEmail()) {
                        $request->merge(['email' => strtolower(trim((string) $socialUser->getEmail()))]);
                    }
                    if (empty($request->name) && $socialUser->getName()) {
                        $request->merge(['name' => $socialUser->getName()]);
                    }
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    ApiResponseService::validationError('رمز دخول Google غير صالح. أعد المحاولة.');
                }
            } elseif ($isEmailType) {
                // already handled above — this branch is unreachable
            } else {
                $verifiedToken = ApiService::verifyFirebaseToken($request->firebase_token);
                $firebaseId = $verifiedToken->claims()->get('sub');
            }

            $socialLogin = null;
            if (!$isEmailType && $firebaseId) {
                $socialLogin = SocialLogin::where('firebase_id', $firebaseId)
                    ->where('type', $request->type)
                    ->with('user', static function ($q): void {
                        $q->withTrashed();
                    })
                    ->whereHas('user', static function ($q): void {
                        RoleManager::applyRoleFilter($q, 'user');
                    })
                    ->first();
            }

            if (!$isEmailType && !empty($socialLogin?->user?->deleted_at)) {
                ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
            }

            $wasRecentlyCreated = false;

            if ($isEmailType || empty($socialLogin)) {
                DB::beginTransaction();
                try {
                    $unique['email'] = $request->email;

                    // Prepare user data
                    $userData = $request->except(['password', 'firebase_token', 'platform_type', 'fcm_id', 'wallet_balance', 'is_active', 'allowed_devices_count', 'role_id']); // Exclude sensitive/pass-through fields
                    $userData['email'] = $request->email;

                    // Ensure name is always set - this is required field in database
                    if (empty($userData['name'])) {
                        // Generate name from email if not provided
                        $userData['name'] = explode('@', $request->email)[0] ?? 'User';
                    }

                    // Generate slug before creating user - use name if available, otherwise use email or default
                    $slugSource = $userData['name'] ?? $request->email ?? 'user';
                    $slug = HelperService::generateUniqueSlug(User::class, $slugSource);
                    $userData['slug'] = $slug;

                    $userData['profile'] = $request->hasFile('profile')
                        ? $request->file('profile')->store('user_profile', 'public')
                        : $request->profile;
                    $userData['is_active'] = 1;
                    $userData['type'] = $request->type;
                    if (!empty($request->mobile)) {
                        $userData['mobile'] = $request->mobile;
                    }

                    // Hash password if type is email
                    if ($request->type === 'email' && !empty($request->password)) {
                        $userData['password'] = Hash::make($request->password);
                    }

                    $hasReferredBy = \Illuminate\Support\Facades\Cache::remember('schema_users_has_referred_by', 3600, function () {
                        return \Illuminate\Support\Facades\Schema::hasColumn('users', 'referred_by');
                    });
                    if ($hasReferredBy) {
                        $affiliateCode = $request->cookie('affiliate_code')
                            ?? $request->cookie('referral_code')
                            ?? ($request->hasSession() ? $request->session()->get('affiliate_code') : null)
                            ?? $request->input('referral')
                            ?? $request->input('affiliate_code');
                            
                        if (!empty($affiliateCode)) {
                            $affiliateLink = \App\Models\AffiliateLink::where('code', $affiliateCode)->where('is_active', true)->first();
                            if ($affiliateLink) {
                                $userData['referred_by'] = $affiliateLink->user_id;
                            } else {
                                if ($request->has('referral') || $request->has('affiliate_code')) {
                                    DB::rollBack();
                                    return response()->json([
                                        'success' => false,
                                        'message' => 'Invalid referral code',
                                        'errors' => [
                                            'referral' => ['Invalid referral code']
                                        ]
                                    ], 422);
                                }
                            }
                        }
                    }

                    if ($isEmailType) {
                        if ($convertingGuest) {
                            $convertingGuest->fill([
                                'name' => $userData['name'] ?? $convertingGuest->name,
                                'password' => $userData['password'] ?? $convertingGuest->password,
                                'mobile' => $userData['mobile'] ?? $convertingGuest->mobile,
                                'profile' => $userData['profile'] ?? $convertingGuest->profile,
                                'is_active' => 1,
                                'type' => 'email',
                                'is_webinar_guest' => false,
                            ]);
                            $convertingGuest->save();
                            $user = $convertingGuest;
                            $wasRecentlyCreated = false;
                        } else {
                            $user = User::create($userData);
                            $wasRecentlyCreated = true;
                        }
                    } else {
                        $user = User::updateOrCreate($unique, $userData);
                        $wasRecentlyCreated = $user->wasRecentlyCreated;
                    }

                    // Only link Firebase SocialLogin for non-email types
                    if (!$isEmailType && $firebaseId) {
                        SocialLogin::updateOrCreate([
                            'type'    => $request->type,
                            'user_id' => $user->id,
                        ], [
                            'firebase_id' => $firebaseId,
                        ]);
                    }
                    RoleManager::assignStudentRole($user);
                    $auth = $user;

                    $deviceError = $this->verifyDeviceLimits($auth, $request);
                    if ($deviceError) {
                        DB::rollBack();
                        ApiResponseService::errorResponse($deviceError['message'], ['error_code' => $deviceError['code'] ?? 'DEVICE_ERROR'], 403);
                    }

                    DB::commit();

                    if ($wasRecentlyCreated) {
                        try {
                            $user->notify(new \App\Notifications\WelcomeNotification($user));
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning('Failed to send welcome notification: ' . $e->getMessage());
                        }
                    }

                    // Server-side tracking (dispatched asynchronously)
                    \App\Jobs\SendTrackingEventJob::dispatch('facebook', 'CompleteRegistration', [
                        'user_data' => [
                            'em' => hash('sha256', $user->email),
                            'ph' => $user->mobile ? hash('sha256', $user->mobile) : null,
                            'client_ip_address' => $request->ip(),
                            'client_user_agent' => $request->userAgent(),
                        ],
                    ]);
                    \App\Jobs\SendTrackingEventJob::dispatch('ga4', 'sign_up', [
                        'params' => ['method' => $request->type],
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    if ($e->getCode() == 23000 || str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'users_email_unique')) {
                        ApiResponseService::validationError(
                            'يوجد حساب بهذا البريد الإلكتروني. يرجى تسجيل الدخول.',
                            ['error_code' => 'EMAIL_ALREADY_REGISTERED'],
                        );
                    }
                    throw $e;
                } catch (\Throwable $th) {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    throw $th;
                }
            } else {
                $auth = $socialLogin->user;
            }

            if (!$auth->hasAnyRole(RoleManager::getCandidateRoleNames('user'))) {
                ApiResponseService::validationError('بيانات الدخول غير صحيحة.');
            }

            $fcmId = $request->fcm_id ?? $request->fcm_token;
            if (!empty($fcmId)) {
                UserFcmToken::updateOrCreate(['fcm_token' => $fcmId], [
                    'user_id' => $auth->id,
                    'platform_type' => $request->platform_type,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            // Verify device limits
            $deviceError = $this->verifyDeviceLimits($auth, $request);
            if ($deviceError) {
                ApiResponseService::errorResponse($deviceError['message'], ['error_code' => $deviceError['code'] ?? 'DEVICE_ERROR'], 403);
            }

            $pair          = $this->createTokenPair($auth, $request->device_id ?? $auth->name ?? '', $request);
            $formattedUser = $this->formatUserWithRolesAndPermissions($auth, $pair['access'], $pair['refresh']);
            ApiResponseService::successResponse('تم تسجيل الدخول بنجاح', $formattedUser);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Issue a dual-token pair for a user:
     *   - access_token  : short-lived (SANCTUM_ACCESS_TOKEN_LIFETIME minutes, default 60)
     *   - refresh_token : long-lived  (SANCTUM_REFRESH_TOKEN_LIFETIME minutes, default 30 days)
     *
     * Both tokens are stored in personal_access_tokens with different
     * token_type values and different expires_at timestamps.
     *
     * @return array{access: string, refresh: string}
     */
    protected function createTokenPair($user, string $baseName, Request $request): array
    {

        $accessMinutes  = (int) config('sanctum.access_token_lifetime',  60);
        $refreshMinutes = (int) config('sanctum.refresh_token_lifetime', 43200);

        // ── Issue access token ───────────────────────────────────────────────
        $accessResult = $user->createToken($baseName, ['access-api', '*'], now()->addMinutes($accessMinutes));
        $accessToken  = $accessResult->accessToken;
        $accessToken->token_type = 'access';
        $accessToken->ip_address = $request->ip();
        $accessToken->user_agent = $request->userAgent();
        $accessToken->save();

        // ── Issue refresh token ──────────────────────────────────────────────
        $refreshResult = $user->createToken($baseName . '-refresh', ['issue-token'], now()->addMinutes($refreshMinutes));
        $refreshToken  = $refreshResult->accessToken;
        $refreshToken->token_type = 'refresh';
        $refreshToken->ip_address = $request->ip();
        $refreshToken->user_agent = $request->userAgent();
        $refreshToken->save();

        return [
            'access'  => $accessResult->plainTextToken,
            'refresh' => $refreshResult->plainTextToken,
        ];
    }

    /**
     * @deprecated Use createTokenPair() instead.
     * Kept for internal compatibility during transition — wraps createTokenPair.
     */
    private function createTokenWithMetadata($user, $name, Request $request): string
    {
        $pair = $this->createTokenPair($user, $request->device_id ?? $name, $request);
        return $pair['access'];
    }

    /**
     * Verify device limits for user login.
     * Returns null if allowed, or error response if blocked.
     */
    private function verifyDeviceLimits(User $user, Request $request)
    {
        return \App\Services\AuthDeviceService::verifyDeviceLimits($user, $request);
    }

    public function userLogin(Request $request)
    {
        try {
            $this->aliasSocialAuthType($request);
            if ($request->has('email') && !empty($request->email)) {
                $request->merge(['email' => strtolower(trim((string) $request->email))]);
            }

            $isEmailType = ($request->type === 'email');
            $isWebOAuth  = !$isEmailType
                && ($request->device_type === 'web' || empty($request->device_type))
                && in_array($request->type, ['google', 'apple'])
                && empty($request->firebase_token);

            // Validation rules — firebase_token only required for google/apple
            $validationRules = [
                'type'          => 'required|in:google,apple,email',
                'platform_type' => 'nullable|in:android,ios',
                'firebase_token'=> ($isEmailType || $isWebOAuth) ? 'nullable|string' : 'required|string',
                'device_type'   => 'nullable|string|in:web,android,ios,desktop',
                'device_id'     => 'nullable|string|max:255',
                'device_name'   => 'nullable|string|max:255',
            ];

            if ($isEmailType) {
                $validationRules['password'] = 'required|string|min:6';
                $validationRules['email']    = 'required|email';
            }

            ApiService::validateRequest($request, $validationRules);

            // ── Email / Password path — NO Firebase ──────────────────────────
            if ($isEmailType) {
                $user = RoleManager::applyRoleFilter(User::withTrashed()->where('email', $request->email), 'user')
                    ->first();

                if (!$user) {
                    ApiResponseService::validationError(
                        'المستخدم غير موجود. يرجى إنشاء حساب أولاً.',
                        ['error_code' => 'ACCOUNT_NOT_FOUND'],
                    );
                }

                if ($user->trashed() || (isset($user->is_active) && !$user->is_active)) {
                    ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
                }

                if (!Hash::check($request->password, $user->password ?? '')) {
                    ApiResponseService::validationError('البريد الإلكتروني أو كلمة المرور غير صحيحة.');
                }

                $auth = $user;

            } else {
                // ── Firebase / Socialite path (google / apple) ─────────────────
                $firebaseId = null;

                if ($isWebOAuth && $request->type === 'google') {
                    $accessToken = $request->input('access_token')
                        ?? $request->input('provider_token')
                        ?? $request->input('token');

                    if (empty($accessToken)) {
                        ApiResponseService::validationError('رمز دخول Google مطلوب لتسجيل الدخول عبر الويب.');
                    }

                    try {
                        $socialUser = \Laravel\Socialite\Facades\Socialite::driver('google')->stateless()->userFromToken($accessToken);
                        $firebaseId = 'google-oauth-' . $socialUser->getId();
                    } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        ApiResponseService::validationError('رمز دخول Google غير صالح. أعد المحاولة.');
                    }
                } else {
                    $verifiedToken = ApiService::verifyFirebaseToken($request->firebase_token);
                    $firebaseId    = $verifiedToken->claims()->get('sub');
                }

                $socialLogin = SocialLogin::where('firebase_id', $firebaseId)
                    ->where('type', $request->type)
                    ->with('user', static function ($q): void {
                        $q->withTrashed();
                    })
                    ->whereHas('user', static function ($q): void {
                        RoleManager::applyRoleFilter($q, 'user');
                    })
                    ->first();

                if (empty($socialLogin)) {
                    ApiResponseService::validationError('المستخدم غير موجود. يرجى إنشاء حساب أولاً.');
                }

                if (!empty($socialLogin->user->deleted_at)) {
                    ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
                }

                $auth = $socialLogin->user;
            }

            // ── Shared post-auth logic ───────────────────────────────────────
            if (!$auth->hasAnyRole(RoleManager::getCandidateRoleNames('user'))) {
                ApiResponseService::validationError('بيانات الدخول غير صحيحة.');
            }

            $fcmId = $request->fcm_id ?? $request->fcm_token;
            if (!empty($fcmId)) {
                UserFcmToken::updateOrCreate(['fcm_token' => $fcmId], [
                    'user_id'       => $auth->id,
                    'platform_type' => $request->platform_type,
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ]);
            }

            $deviceError = $this->verifyDeviceLimits($auth, $request);
            if ($deviceError) {
                ApiResponseService::errorResponse($deviceError['message'], ['error_code' => $deviceError['code'] ?? 'DEVICE_ERROR'], 403);
            }

            $pair          = $this->createTokenPair($auth, $request->device_id ?? $auth->name ?? '', $request);
            $formattedUser = $this->formatUserWithRolesAndPermissions($auth, $pair['access'], $pair['refresh']);
            ApiResponseService::successResponse('تم تسجيل الدخول بنجاح', $formattedUser);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }


    public function mobileLogin(Request $request)
    {
        try {
            $this->normalizePhoneRequest($request);

            ApiService::validateRequest($request, [
                'mobile' => 'required|numeric',
                'country_calling_code' => 'required|string',
                'password' => 'required|string|min:6',
                'fcm_id' => 'nullable|string',
                'platform_type' => 'nullable|in:android,ios',
                'device_type' => 'nullable|in:web,android,ios,desktop',
                'device_id' => 'nullable|string|max:255',
                'device_name' => 'nullable|string|max:255',
            ]);

            $rawMobile = (string) $request->mobile;
            $normalizedMobile = preg_replace('/\D+/', '', $rawMobile) ?? '';
            $trimmedMobile = ltrim($normalizedMobile, '0');
            $mobileVariants = array_unique(array_filter([
                $rawMobile,
                $normalizedMobile,
                $trimmedMobile,
                '0' . $trimmedMobile,
            ]));

            $rawCode = (string) $request->country_calling_code;
            $normalizedCode = preg_replace('/\D+/', '', $rawCode) ?? '';
            $codeVariants = array_unique(array_filter([
                $rawCode,
                '+' . $normalizedCode,
                $normalizedCode,
            ]));

            $user = RoleManager::applyRoleFilter(
                User::withTrashed()
                    ->whereIn('mobile', $mobileVariants)
                    ->whereIn('country_calling_code', $codeVariants),
                'user'
            )->first();

            if (!$user) {
                ApiResponseService::validationError(
                    'المستخدم غير موجود.',
                    ['error_code' => 'ACCOUNT_NOT_FOUND'],
                );
            }

            if ($user->trashed() || (isset($user->is_active) && !$user->is_active)) {
                ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
            }

            if (!Hash::check($request->password, $user->password ?? '')) {
                ApiResponseService::validationError('كلمة المرور غير صحيحة.');
            }

            // Update FCM token if provided
            $fcmId = $request->fcm_id ?? $request->fcm_token;
            if (!empty($fcmId)) {
                UserFcmToken::updateOrCreate(['fcm_token' => $fcmId], [
                    'user_id' => $user->id,
                    'platform_type' => $request->platform_type,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            // Verify device limits
            $deviceError = $this->verifyDeviceLimits($user, $request);
            if ($deviceError) {
                ApiResponseService::errorResponse($deviceError['message'], ['error_code' => $deviceError['code'] ?? 'DEVICE_ERROR'], 403);
            }

            // Generate new token pair
            $pair          = $this->createTokenPair($user, $request->device_id ?? $user->name ?? '', $request);
            $formattedUser = $this->formatUserWithRolesAndPermissions($user, $pair['access'], $pair['refresh']);
            ApiResponseService::successResponse('تم تسجيل الدخول بنجاح', $formattedUser);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    public function refreshToken(Request $request)
    {
        try {
            // ── 1. Validate the incoming refresh token ────────────────────
            // The frontend sends the refresh_token in the Authorization header
            // AND optionally in the request body. Sanctum's auth:sanctum guard
            // already authenticated the request using whichever was in the header.
            $currentToken = $request->user()->currentAccessToken();

            // Ensure the token being used IS a refresh token, not an access token.
            // This prevents access tokens from being used to obtain new token pairs.
            if (($currentToken->token_type ?? 'access') !== 'refresh') {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid token type. A refresh_token is required.',
                ], 401);
            }

            // Check the token has not expired (Sanctum checks expires_at automatically
            // but we double-check for extra safety with a clear error message).
            if ($currentToken->expires_at && $currentToken->expires_at->isPast()) {
                $currentToken->delete();
                return response()->json([
                    'status'  => false,
                    'message' => 'Refresh token has expired. Please log in again.',
                ], 401);
            }

            $user = $request->user();

            if (!$user) {
                return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);
            }

            if ($user->trashed() || (isset($user->is_active) && !$user->is_active)) {
                $currentToken->delete();
                return ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
            }

            // ── 2. Revoke old refresh token + issue new pair atomically ──
            $pair = DB::transaction(function () use ($currentToken, $user, $request) {
                $deleted = DB::table('personal_access_tokens')
                    ->where('id', $currentToken->id)
                    ->delete();

                if ($deleted === 0) {
                    return null;
                }

                return $this->createTokenPair($user, $request->device_id ?? $user->name ?? 'refresh', $request);
            });

            if ($pair === null) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Refresh token has already been used or revoked.',
                ], 401);
            }

            // ── 3. Return new token pair ──────────────────────────────────
            return response()->json([
                'status'        => true,
                'message'       => 'Token refreshed successfully.',
                'token'         => $pair['access'],
                'refresh_token' => $pair['refresh'],
                'expires_in'    => config('sanctum.access_token_lifetime', 60) * 60, // seconds
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    public function mobileRegistration(Request $request)
    {
        try {
            $this->normalizePhoneRequest($request);
            if ($request->filled('password_confirmation') && !$request->filled('confirm_password')) {
                $request->merge(['confirm_password' => $request->input('password_confirmation')]);
            }
            if ($request->filled('confirm_password') && !$request->filled('password_confirmation')) {
                $request->merge(['password_confirmation' => $request->input('confirm_password')]);
            }

            ApiService::validateRequest($request, [
                'mobile' => [
                    'required',
                    'numeric',
                    Rule::unique('users', 'mobile')->where(function ($query) use ($request) {
                        $rawCode = (string) $request->input('country_calling_code');
                        $normalizedCode = preg_replace('/\D+/', '', $rawCode) ?? '';
                        $codeVariants = array_unique(array_filter([
                            $rawCode,
                            '+' . $normalizedCode,
                            $normalizedCode,
                        ]));
                        return $query->whereIn('country_calling_code', $codeVariants)->whereNull('deleted_at');
                    }),
                ],
                'password' => 'required|string|min:6',
                'confirm_password' => 'required|same:password',
                'name' => 'required|string|max:255',
                'fcm_id' => 'nullable|string',
                'platform_type' => 'nullable|in:android,ios',
                'firebase_token' => 'required|string',
                'email' => 'nullable|email',
                'country_calling_code' => 'required|string|max:10',
                'device_type' => 'nullable|in:web,android,ios,desktop',
                'device_id' => 'nullable|string|max:255',
                'device_name' => 'nullable|string|max:255',
            ]);

            $verifiedToken = ApiService::verifyFirebaseToken($request->firebase_token);
            $claims = $verifiedToken->claims();
            $firebaseId = $claims->get('sub');
            $verifiedPhone = $claims->get('phone_number');

            if (empty($firebaseId) || empty($verifiedPhone)) {
                ApiResponseService::validationError('يجب تأكيد رقم الهاتف عبر رمز التحقق أولاً.');
            }

            if (!$this->phoneNumbersMatch(
                (string) $verifiedPhone,
                (string) $request->country_calling_code,
                (string) $request->mobile,
            )) {
                ApiResponseService::validationError(
                    'رقم الهاتف المؤكد لا يطابق الرقم المُرسل.',
                );
            }

            $rawMobile = (string) $request->mobile;
            $normalizedMobile = preg_replace('/\D+/', '', $rawMobile) ?? '';
            $trimmedMobile = ltrim($normalizedMobile, '0');
            $mobileVariants = array_unique(array_filter([
                $rawMobile,
                $normalizedMobile,
                $trimmedMobile,
                '0' . $trimmedMobile,
            ]));

            $rawCode = (string) $request->country_calling_code;
            $normalizedCode = preg_replace('/\D+/', '', $rawCode) ?? '';
            $codeVariants = array_unique(array_filter([
                $rawCode,
                '+' . $normalizedCode,
                $normalizedCode,
            ]));

            $existingUser = User::withTrashed()
                ->whereIn('mobile', $mobileVariants)
                ->whereIn('country_calling_code', $codeVariants)
                ->first();

            if ($existingUser) {
                if ($existingUser->trashed() || (isset($existingUser->is_active) && !$existingUser->is_active)) {
                    ApiResponseService::validationError('تم تعطيل هذا الحساب. يرجى التواصل مع الدعم.');
                } else {
                    ApiResponseService::validationError('يوجد حساب بهذا الرقم. يرجى تسجيل الدخول.');
                }
            }

            DB::beginTransaction();

            // Create new user
            $slugSource = $request->name ?? $request->mobile ?? 'user';
            $userData = [
                'name' => $request->name,
                'slug' => HelperService::generateUniqueSlug(User::class, $slugSource),
                'mobile' => $request->mobile,
                'password' => Hash::make($request->password),
                'country_calling_code' => $request->input('country_calling_code'),
                'type' => 'mobile',
                'email' => $request->filled('email') ? strtolower(trim((string) $request->input('email'))) : null,
            ];
            $hasReferredBy = \Illuminate\Support\Facades\Cache::remember('schema_users_has_referred_by', 3600, function () {
                return \Illuminate\Support\Facades\Schema::hasColumn('users', 'referred_by');
            });
            if ($hasReferredBy) {
                $affiliateCode = $request->cookie('affiliate_code')
                    ?? $request->cookie('referral_code')
                    ?? ($request->hasSession() ? $request->session()->get('affiliate_code') : null)
                    ?? $request->input('referral')
                    ?? $request->input('affiliate_code');
                    
                if (!empty($affiliateCode)) {
                    $affiliateLink = \App\Models\AffiliateLink::where('code', $affiliateCode)->where('is_active', true)->first();
                    if ($affiliateLink) {
                        $userData['referred_by'] = $affiliateLink->user_id;
                    } else {
                        if ($request->has('referral') || $request->has('affiliate_code')) {
                            DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'message' => 'Invalid referral code',
                                'errors' => [
                                    'referral' => ['Invalid referral code']
                                ]
                            ], 422);
                        }
                    }
                }
            }
            $user = User::create($userData);
            $user->notify(new \App\Notifications\WelcomeNotification($user));

            $firebaseAccount = SocialLogin::where('firebase_id', $firebaseId)
                ->where('type', 'phone')
                ->first();

            if ($firebaseAccount && $firebaseAccount->user_id !== $user->id) {
                DB::rollBack();
                ApiResponseService::validationError('حساب الهاتف هذا مرتبط بمستخدم آخر.');
            }

            SocialLogin::updateOrCreate(
                ['user_id' => $user->id, 'type' => 'phone'],
                ['firebase_id' => $firebaseId],
            );
            // Assign Canonical Student role
            RoleManager::assignStudentRole($user);

            // Update FCM token if provided
            $fcmId = $request->fcm_id ?? $request->fcm_token;
            if (!empty($fcmId)) {
                UserFcmToken::updateOrCreate(['fcm_token' => $fcmId], [
                    'user_id' => $user->id,
                    'platform_type' => $request->platform_type,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            // Keep device registration in the transaction so a device-limit
            // failure cannot leave behind a user that the client thinks failed.
            $deviceError = $this->verifyDeviceLimits($user, $request);
            if ($deviceError) {
                DB::rollBack();
                ApiResponseService::errorResponse($deviceError['message'], ['error_code' => $deviceError['code'] ?? 'DEVICE_ERROR'], 403);
            }

            DB::commit();

            // Generate new token pair
            $pair          = $this->createTokenPair($user, $request->device_id ?? $user->name ?? '', $request);
            $formattedUser = $this->formatUserWithRolesAndPermissions($user, $pair['access'], $pair['refresh']);
            ApiResponseService::successResponse('تم إنشاء الحساب بنجاح', $formattedUser);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            DB::rollBack();
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    public function mobileResetPassword(Request $request)
    {
        try {
            $this->normalizePhoneRequest($request);
            ApiService::validateRequest($request, [
                'firebase_token' => 'required|string',
                'mobile' => 'required|numeric',
                'country_calling_code' => 'required|string|max:10',
                'password' => 'required|string|min:6',
                'confirm_password' => 'required|same:password',
            ]);

            $verifiedToken = ApiService::verifyFirebaseToken($request->firebase_token);
            $claims = $verifiedToken->claims();
            $firebaseId = $claims->get('sub');
            $verifiedPhone = $claims->get('phone_number');

            if (empty($firebaseId) || empty($verifiedPhone)) {
                ApiResponseService::validationError('يجب تأكيد رقم الهاتف عبر رمز التحقق أولاً.');
            }

            $socialLogin = SocialLogin::where('firebase_id', $firebaseId)
                ->where('type', 'phone')
                ->with('user')
                ->first();

            if (!$socialLogin || !$socialLogin->user) {
                ApiResponseService::validationError('لا يوجد حساب مرتبط بهذا الرقم المؤكد.');
            }

            $user = $socialLogin->user;

            if ($user->trashed() || (isset($user->is_active) && !$user->is_active)) {
                ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
            }

            $user->password = Hash::make($request->password);
            $user->save();

            ApiResponseService::successResponse('تم إعادة تعيين كلمة المرور بنجاح');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    private function phoneNumbersMatch(string $verifiedPhone, string $callingCode, string $mobile): bool
    {
        $normalize = static fn(string $value): string => preg_replace('/\D+/', '', $value) ?? '';

        $verified = $normalize($verifiedPhone);
        $calling = $normalize($callingCode);
        $mob = $normalize($mobile);

        if ($verified === '' || $mob === '' || $calling === '') {
            return false;
        }

        // Direct comparison if verified equals full national + code
        if (hash_equals($calling . $mob, $verified)) {
            return true;
        }

        // Handle leading zeros stripped (e.g. 01012345678 -> 1012345678)
        $mobNoLeadingZeros = ltrim($mob, '0');
        if ($mobNoLeadingZeros !== '' && hash_equals($calling . $mobNoLeadingZeros, $verified)) {
            return true;
        }

        // Verified number must start with the specific country calling code
        if (str_starts_with($verified, $calling)) {
            $verifiedLocal = substr($verified, strlen($calling));
            if ($verifiedLocal === $mob || $verifiedLocal === $mobNoLeadingZeros || ltrim($verifiedLocal, '0') === $mobNoLeadingZeros) {
                return true;
            }
        }

        // Fallback: Check if verified ends with normalized national digits
        if ($mobNoLeadingZeros !== '' && str_ends_with($verified, $mobNoLeadingZeros)) {
            return true;
        }

        return false;
    }

    /**
     * Admin login - email + password, returns Sanctum token.
     * Only users with admin/staff roles (Super Admin, Admin, Staff, Supervisor) can login.
     */
    public function adminLogin(Request $request)
    {
        try {
            ApiService::validateRequest($request, [
                'email' => 'required|email',
                'password' => 'required|string',
                'device_type' => 'nullable|in:web,android,ios,desktop',
                'device_id' => 'nullable|string|max:255',
                'device_name' => 'nullable|string|max:255',
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                ApiResponseService::validationError(__('Invalid credentials'));
            }

            if (!empty($user->deleted_at)) {
                ApiResponseService::validationError(__('Account is deactivated. Please contact the administrator'));
            }

            if (!Hash::check($request->password, $user->password)) {
                ApiResponseService::validationError(__('Invalid credentials'));
            }

            // Accept both old 'Admin' and new 'Super Admin' during transition period
            $adminRoles = [
                'Admin',          // legacy name
                'Super Admin',    // new name
                config('constants.SYSTEM_ROLES.SUPER_ADMIN'),
                config('constants.SYSTEM_ROLES.STAFF'),
                config('constants.SYSTEM_ROLES.SUPERVISOR'),
            ];
            if (!$user->hasAnyRole($adminRoles, 'web')) {
                ApiResponseService::validationError(__('Access denied. Admin credentials required.'));
            }

            // Verify device limits
            $deviceError = $this->verifyDeviceLimits($user, $request);
            if ($deviceError) {
                ApiResponseService::errorResponse($deviceError['message'], ['error_code' => $deviceError['code'] ?? 'DEVICE_ERROR'], 403);
            }

            $pair          = $this->createTokenPair($user, $request->device_id ?? 'admin-dashboard', $request);
            $userData      = $user->toArray();
            $userData['token']         = $pair['access'];
            $userData['refresh_token'] = $pair['refresh'];
            $userData['token_type']    = 'Bearer';
            $userData['expires_in']    = config('sanctum.access_token_lifetime', 60) * 60; // seconds

            try {
                AuditLogService::log(
                    action: 'admin_login',
                    target: $user,
                    summary: 'تسجيل دخول لوحة التحكم',
                    details: [
                        'device_type' => $request->input('device_type'),
                        'device_id' => $request->input('device_id'),
                    ],
                    actor: $user,
                );
            } catch (\Throwable) {
            }

            ApiResponseService::successResponse(__('تم تسجيل الدخول بنجاح'), $userData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Map type=social + provider=google|apple to the canonical type the validators expect.
     */
    protected function aliasSocialAuthType(Request $request): void
    {
        $type = strtolower(trim((string) $request->input('type', '')));
        if ($type !== 'social') {
            return;
        }
        $provider = strtolower(trim((string) $request->input('provider', 'google')));
        if (in_array($provider, ['google', 'apple'], true)) {
            $request->merge(['type' => $provider]);
        }
    }

    /**
     * Store EG-style local numbers without country code or leading zero.
     * country_calling_code is always +digits (e.g. +20).
     */
    protected function normalizePhoneRequest(Request $request): void
    {
        if (!$request->has('mobile') && !$request->has('phone')) {
            return;
        }

        $rawMobile = (string) ($request->input('mobile') ?? $request->input('phone') ?? '');
        $rawCode = (string) $request->input('country_calling_code', '');
        $codeDigits = preg_replace('/\D+/', '', $rawCode) ?? '';
        $mobileDigits = preg_replace('/\D+/', '', $rawMobile) ?? '';

        if ($codeDigits !== '' && str_starts_with($mobileDigits, $codeDigits)) {
            $mobileDigits = substr($mobileDigits, strlen($codeDigits));
        }
        $mobileDigits = ltrim($mobileDigits, '0');

        $merge = ['mobile' => $mobileDigits];
        if ($codeDigits !== '') {
            $merge['country_calling_code'] = '+' . $codeDigits;
        }
        $request->merge($merge);
    }
}
