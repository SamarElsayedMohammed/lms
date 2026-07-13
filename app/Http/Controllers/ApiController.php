<?php

namespace App\Http\Controllers;

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

class ApiController extends Controller
{
    public function __construct()
    {
        if (array_key_exists('HTTP_AUTHORIZATION', $_SERVER) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $this->middleware('auth:sanctum');
        }
    }

    public function userExists(Request $request)
    {
        try {
            ApiService::validateRequest($request, [
                'country_calling_code' => 'required_without:email|string',
                'mobile' => 'required_without:email|numeric',
                'email' => 'required_without:mobile|email',
            ]);

            // Check if user exists (including soft-deleted)
            $userQuery = User::role(config('constants.SYSTEM_ROLES.USER'))
                ->withTrashed()
                ->when($request->has('email'), static function ($query) use ($request): void {
                    $query->where('email', $request->email);
                })
                ->when($request->has('mobile'), static function ($query) use ($request): void {
                    $query->where([
                        'mobile' => $request->mobile,
                        'country_calling_code' => $request->input('country_calling_code'),
                    ]);
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

            if ($isEmailType) {
                // ── Email / Password path — NO Firebase involved ─────────────
                // Check if a user with this email already exists (signup = upsert)
                $existingEmailUser = User::where('email', $request->email)
                    ->withTrashed()
                    ->role(config('constants.SYSTEM_ROLES.USER'))
                    ->first();

                if ($existingEmailUser && !$existingEmailUser->trashed()) {
                    ApiResponseService::validationError(
                        'An account with this email already exists. Please log in instead.',
                    );
                }

                // New email user — no firebase_id needed, skip SocialLogin table
                $firebaseId = null; // not used below for email type

            } elseif ($isWebOAuth && $request->type === 'google') {
                // Web Google login: resolve via Socialite (no Firebase needed)
                $accessToken = $request->input('access_token')
                    ?? $request->input('provider_token')
                    ?? $request->input('token');

                if (empty($accessToken)) {
                    ApiResponseService::validationError('access_token is required for web Google login.');
                }

                try {
                    $socialUser = \Laravel\Socialite\Facades\Socialite::driver('google')->stateless()->userFromToken($accessToken);
                    // Use Google UID as the stable identity key
                    $firebaseId = 'google-oauth-' . $socialUser->getId();

                    // Ensure email is set in request for downstream code
                    if (empty($request->email) && $socialUser->getEmail()) {
                        $request->merge(['email' => $socialUser->getEmail()]);
                    }
                    if (empty($request->name) && $socialUser->getName()) {
                        $request->merge(['name' => $socialUser->getName()]);
                    }
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    ApiResponseService::validationError('Invalid Google access token: ' . $e->getMessage());
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
                        $q->role(config('constants.SYSTEM_ROLES.USER'));
                    })
                    ->first();
            }

            if (!$isEmailType && !empty($socialLogin?->user?->deleted_at)) {
                ApiResponseService::validationError('User is deactivated. Please Contact the administrator');
            }

            if ($isEmailType || empty($socialLogin)) {
                DB::beginTransaction();
                $unique['email'] = $request->email;

                // Prepare user data
                $userData = $request->except(['password', 'firebase_token', 'platform_type', 'fcm_id', 'wallet_balance', 'is_active', 'allowed_devices_count', 'role_id']); // Exclude sensitive/pass-through fields

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

                $user = User::updateOrCreate($unique, $userData);
                if ($user->wasRecentlyCreated) {
                    $user->notify(new \App\Notifications\WelcomeNotification($user));
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
                $user->assignRole(config('constants.SYSTEM_ROLES.USER'));
                Auth::login($user);
                $auth = User::find($user->id);

                $deviceError = $this->verifyDeviceLimits($auth, $request);
                if ($deviceError) {
                    DB::rollBack();
                    ApiResponseService::errorResponse($deviceError['message'], ['error_code' => $deviceError['code'] ?? 'DEVICE_ERROR'], 403);
                }

                DB::commit();

                // Server-side tracking
                \App\Services\TrackingService::sendFacebookEvent('CompleteRegistration', [
                    'em' => hash('sha256', $user->email),
                    'ph' => $user->mobile ? hash('sha256', $user->mobile) : null,
                ]);
                \App\Services\TrackingService::sendGA4Event('sign_up', ['method' => $request->type]);
            } else {
                Auth::login($socialLogin->user);
                $auth = Auth::user();
            }

            if (!$auth->hasRole(config('constants.SYSTEM_ROLES.USER'))) {
                ApiResponseService::validationError('Invalid Login Credentials');
            }

            if (!empty($request->fcm_id)) {
                UserFcmToken::updateOrCreate(['fcm_token' => $request->fcm_id], [
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
            ApiResponseService::successResponse('User logged-in successfully', $formattedUser);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            DB::rollBack();
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
        $accessResult = $user->createToken($baseName, ['*'], now()->addMinutes($accessMinutes));
        $accessToken  = $accessResult->accessToken;
        $accessToken->token_type = 'access';
        $accessToken->ip_address = $request->ip();
        $accessToken->user_agent = $request->userAgent();
        $accessToken->save();

        // ── Issue refresh token ──────────────────────────────────────────────
        $refreshResult = $user->createToken($baseName . '-refresh', ['*'], now()->addMinutes($refreshMinutes));
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
            $isEmailType = ($request->type === 'email');
            $isWebOAuth  = !$isEmailType
                && ($request->device_type === 'web' || empty($request->device_type))
                && in_array($request->type, ['google', 'apple'])
                && empty($request->firebase_token);

            // Validation rules \u2014 firebase_token only required for google/apple
            $validationRules = [
                'type'          => 'required|in:google,apple,email',
                'platform_type' => 'nullable|in:android,ios',
                'firebase_token'=> ($isEmailType || $isWebOAuth) ? 'nullable|string' : 'required|string',
                'device_type'   => 'required|string|in:web,android,ios',
                'device_id'     => 'required|string|max:255',
                'device_name'   => 'nullable|string|max:255',
            ];

            if ($isEmailType) {
                $validationRules['password'] = 'required|string|min:6';
                $validationRules['email']    = 'required|email';
            }

            ApiService::validateRequest($request, $validationRules);

            // \u2500\u2500 Email / Password path \u2014 no Firebase \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
            if ($isEmailType) {
                $user = User::withTrashed()
                    ->role(config('constants.SYSTEM_ROLES.USER'))
                    ->where('email', $request->email)
                    ->first();

                if (!$user) {
                    ApiResponseService::validationError('User not found. Please sign up first.');
                }

                if ($user->trashed()) {
                    ApiResponseService::validationError('User is deactivated. Please contact the administrator.');
                }

                if (!Hash::check($request->password, $user->password ?? '')) {
                    ApiResponseService::validationError('Invalid email or password.');
                }

                $auth = $user;

            } else {
                // \u2500\u2500 Firebase / Socialite path (google / apple) \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
                $firebaseId = null;

                if ($isWebOAuth && $request->type === 'google') {
                    $accessToken = $request->input('access_token')
                        ?? $request->input('provider_token')
                        ?? $request->input('token');

                    if (empty($accessToken)) {
                        ApiResponseService::validationError('access_token is required for web Google login.');
                    }

                    try {
                        $socialUser = \Laravel\Socialite\Facades\Socialite::driver('google')->stateless()->userFromToken($accessToken);
                        $firebaseId = 'google-oauth-' . $socialUser->getId();
                    } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        ApiResponseService::validationError('Invalid Google access token: ' . $e->getMessage());
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
                        $q->role(config('constants.SYSTEM_ROLES.USER'));
                    })
                    ->first();

                if (empty($socialLogin)) {
                    ApiResponseService::validationError('User not found. Please sign up first.');
                }

                if (!empty($socialLogin->user->deleted_at)) {
                    ApiResponseService::validationError('User is deactivated. Please Contact the administrator');
                }

                Auth::login($socialLogin->user);
                $auth = Auth::user();
            }

            // \u2500\u2500 Shared post-auth logic \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
            if (!$auth->hasRole(config('constants.SYSTEM_ROLES.USER'))) {
                ApiResponseService::validationError('Invalid Login Credentials');
            }

            if (!empty($request->fcm_id)) {
                UserFcmToken::updateOrCreate(['fcm_token' => $request->fcm_id], [
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
            ApiResponseService::successResponse('User logged-in successfully', $formattedUser);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }


    public function mobileLogin(Request $request)
    {
        try {
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

            $user = User::withTrashed()
                ->role(config('constants.SYSTEM_ROLES.USER'))
                ->where('mobile', $request->mobile)
                ->where('country_calling_code', $request->country_calling_code)
                ->first();

            if (!$user) {
                ApiResponseService::validationError('User Not Found');
            }

            if (!empty($user->deleted_at)) {
                ApiResponseService::validationError('User is deactivated. Please contact the administrator');
            }

            if (!Hash::check($request->password, $user->password)) {
                ApiResponseService::validationError('Invalid password');
            }

            // Update FCM token if provided
            if (!empty($request->fcm_id)) {
                UserFcmToken::updateOrCreate(['fcm_token' => $request->fcm_id], [
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
            ApiResponseService::successResponse('Login successful', $formattedUser);
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

            // ── 2. Revoke old pair + issue new pair ───────────────────────
            // createTokenPair() used to delete ALL tokens. Now it issues a new pair for the session.
            $pair = $this->createTokenPair($user, $request->device_id ?? $user->name ?? 'refresh', $request);

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
            ApiService::validateRequest($request, [
                'mobile' => [
                    'required',
                    Rule::unique('users', 'mobile')->whereNull('deleted_at'),
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
                ApiResponseService::validationError('Firebase phone verification is required');
            }

            if (!$this->phoneNumbersMatch(
                (string) $verifiedPhone,
                (string) $request->country_calling_code,
                (string) $request->mobile,
            )) {
                ApiResponseService::validationError(
                    'The verified Firebase phone number does not match the submitted phone number',
                );
            }

            $existingUser = User::where('mobile', $request->mobile)
                ->where('country_calling_code', $request->country_calling_code)
                ->whereNull('deleted_at')
                ->first();

            if ($existingUser) {
                ApiResponseService::validationError('User already exists');
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
                'email' => $request->input('email'),
            ];
            $hasReferredBy = \Illuminate\Support\Facades\Cache::remember('schema_users_has_referred_by', 3600, function () {
                return \Illuminate\Support\Facades\Schema::hasColumn('users', 'referred_by');
            });
            if ($hasReferredBy) {
                $affiliateCode = $request->cookie('affiliate_code')
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
                ApiResponseService::validationError('This Firebase phone account is already linked to another user');
            }

            SocialLogin::updateOrCreate(
                ['user_id' => $user->id, 'type' => 'phone'],
                ['firebase_id' => $firebaseId],
            );
            // Assign General User role
            $user->assignRole(config('constants.SYSTEM_ROLES.USER'));

            // Update FCM token if provided
            if (!empty($request->fcm_id)) {
                UserFcmToken::updateOrCreate(['fcm_token' => $request->fcm_id], [
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
            ApiResponseService::successResponse('Registration successful', $formattedUser);
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
                ApiResponseService::validationError('Firebase phone verification is required');
            }

            if (!$this->phoneNumbersMatch(
                (string) $verifiedPhone,
                (string) $request->country_calling_code,
                (string) $request->mobile,
            )) {
                ApiResponseService::validationError(
                    'The verified Firebase phone number does not match the submitted phone number',
                );
            }

            $socialLogin = SocialLogin::where('firebase_id', $firebaseId)
                ->where('type', 'phone')
                ->with('user')
                ->first();

            $user = $socialLogin?->user;
            if (!$user
                || $user->trashed()
                || !$user->is_active
                || !$this->phoneNumbersMatch(
                    (string) $verifiedPhone,
                    (string) $user->country_calling_code,
                    (string) $user->mobile,
                )) {
                ApiResponseService::validationError('User not found');
            }

            $user->update([
                'password' => Hash::make($request->password),
            ]);
            $user->tokens()->delete();

            ApiResponseService::successResponse('Password reset successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    private function phoneNumbersMatch(string $verifiedPhone, string $callingCode, string $mobile): bool
    {
        $normalize = static fn(string $value): string => preg_replace('/\D+/', '', $value) ?? '';

        return $normalize($verifiedPhone) !== ''
            && hash_equals(
                $normalize($callingCode . $mobile),
                $normalize($verifiedPhone),
            );
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
                config('constants.SYSTEM_ROLES.INSTRUCTOR'),
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

            ApiResponseService::successResponse(__('Login successful'), $userData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    public function getUserDetails(Request $request)
    {
        try {
            /** @var User */
            $user = User::where(['id' => Auth::user()?->id, 'is_active' => 1])->with([
                'instructor_details.personal_details',
                'instructor_details.social_medias.social_media',
                'instructor_details.other_details.custom_form_field',
                'instructor_details.other_details.custom_form_field_option',
                'activeSubscription.plan',
            ])->first();

            if (empty($user)) {
                ApiResponseService::validationError('User not found');
            }

            // Refresh instructor_details relationship to get latest status

            // Convert user to array to avoid model casting issues
            $userData = $user->toArray();

            // Add custom fields
            $userData['is_instructor'] = $user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));
            $userData['instructor_process_status'] = $user->instructor_details->status ?? 'pending';

            // Convert wallet_balance to float to ensure it's returned as a number, not string
            $userData['wallet_balance'] = $user->wallet_balance ?? 0;

            if ($user->activeSubscription) {
                $userData['active_subscription_type'] = ucfirst($user->activeSubscription->plan->billing_cycle ?? 'unknown');
                $userData['active_subscription_plan_name'] = $user->activeSubscription->plan->name ?? null;
                if ($user->activeSubscription->ends_at) {
                    $days = \Illuminate\Support\Carbon::now()->diffInDays($user->activeSubscription->ends_at, false);
                    $userData['active_subscription_days_left'] = $days > 0 ? (int) $days : 0;
                } else {
                    $userData['active_subscription_days_left'] = 'Lifetime';
                }
            } else {
                $userData['active_subscription_type'] = null;
                $userData['active_subscription_plan_name'] = null;
                $userData['active_subscription_days_left'] = null;
            }

            ApiResponseService::successResponse('User details retrieved successfully', $userData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Update user profile (merged with instructor details)
     * If user is instructor, updates both user profile + instructor details
     * If user is regular user, updates only user profile
     */
    public function updateProfile(Request $request)
    {
        try {
            // Check if user is authenticated
            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::errorResponse('User not authenticated', null, 401);
            }

            $user = User::where(['id' => Auth::id(), 'is_active' => 1])->first();
            if (empty($user)) {
                return ApiResponseService::validationError('User not found');
            }

            // Check if user is instructor
            $isInstructor = $user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));

            // If user is instructor, check if account is suspended and get existing instructor data
            $existingInstructor = null;
            $existingInstructorPersonalDetail = null;
            $hasExistingTeamLogo = false;

            if ($isInstructor) {
                $existingInstructor = Instructor::where('user_id', $user->id)->first();
                if ($existingInstructor && $existingInstructor->status === 'suspended') {
                    return ApiResponseService::errorResponse(
                        'Your instructor account has been suspended. You cannot update your details.',
                    );
                }

                // Check if team_logo already exists
                if ($existingInstructor) {
                    $existingInstructorPersonalDetail = InstructorPersonalDetail::where(
                        'instructor_id',
                        $existingInstructor->id,
                    )->first();
                    if ($existingInstructorPersonalDetail && !empty($existingInstructorPersonalDetail->team_logo)) {
                        $hasExistingTeamLogo = true;
                    }
                }
            }

            // Get max video upload size from settings (in MB), default to 10MB
            // Convert MB to KB for Laravel validation (max rule uses KB)
            $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
            $maxSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 10;
            $maxSizeKB = $maxSizeMB * 1024;

            // Build validation rules based on user type
            $validationRules = [
                'name' => 'nullable|string|max:255',
                'email' => 'nullable|email|unique:users,email,' . Auth::id(),
                'mobile' => 'nullable|string|max:20',
                'country_calling_code' => 'nullable|string|max:10',
                'country_code' => 'nullable|string',
                'profile' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            ];

            // Add instructor-specific validation rules if user is instructor
            if ($isInstructor) {
                // Determine if team_logo should be required
                // Only require if instructor_type is "team" in the request AND there's no existing team_logo
                $instructorType = $request->input('instructor_type');
                $teamLogoRule = 'nullable|file|mimes:jpeg,png,jpg,gif,svg|max:2048';

                // Only require team_logo if:
                // 1. instructor_type is explicitly set to "team" in the request, AND
                // 2. there's no existing team_logo
                if ($instructorType === 'team' && !$hasExistingTeamLogo) {
                    $teamLogoRule = 'required|file|mimes:jpeg,png,jpg,gif,svg|max:2048';
                }

                $validationRules = array_merge($validationRules, [
                    'instructor_type' => 'nullable|in:individual,team',
                    'qualification' => 'nullable|string',
                    'years_of_experience' => 'nullable|numeric|min:0|max:100',
                    'skills' => 'nullable|string',
                    'bank_account_number' => 'nullable|string',
                    'bank_name' => 'nullable|string',
                    'bank_account_holder_name' => 'nullable|string',
                    'bank_ifsc_code' => 'nullable|string',
                    'team_name' => 'nullable|required_if:instructor_type,team|string',
                    'team_logo' => $teamLogoRule,
                    'about_me' => 'nullable|string',
                    'id_proof' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx|max:5120',
                    'preview_video' => 'nullable|file|mimes:mp4,mov,avi,wmv,flv,mpeg,mpg,m4v,webm|max:' . $maxSizeKB,
                    'social_medias' => 'nullable|array',
                    'social_medias.*.title' => 'nullable|string|max:255',
                    'social_medias.*.url' => 'nullable|url',
                    'other_details' => 'nullable|array',
                    'other_details.*.id' => 'nullable|exists:custom_form_fields,id',
                    'other_details.*.option_id' => 'nullable|exists:custom_form_field_options,id',
                    'other_details.*.value' => 'nullable|string',
                    'other_details.*.file' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,mp4,mov,avi,wmv,flv,mpeg,mpg,m4v,webm|max:5120',
                ]);
            }

            $allowedProfileFormats = 'JPEG, PNG, JPG, GIF, SVG, WEBP';
            $allowedTeamLogoFormats = 'JPEG, PNG, JPG, GIF, SVG';

            $customMessages = [
                'profile.file' => "The profile field must be an image. Allowed formats: {$allowedProfileFormats}.",
                'profile.mimes' => "The profile field must be an image. Allowed formats: {$allowedProfileFormats}.",
                'team_logo.mimes' => "The team logo field must be an image. Allowed formats: {$allowedTeamLogoFormats}.",
            ];

            $validator = Validator::make($request->all(), $validationRules, $customMessages);

            if ($validator->fails()) {
                $errors = $validator->errors();

                // Check if profile field has mimes or file validation error
                if ($errors->has('profile.mimes') || $errors->has('profile.file')) {
                    return ApiResponseService::validationError(
                        "The profile field must be an image. Allowed formats: {$allowedProfileFormats}.",
                    );
                }

                // Check if team_logo field has mimes validation error
                if ($errors->has('team_logo.mimes')) {
                    return ApiResponseService::validationError(
                        "The team logo field must be an image. Allowed formats: {$allowedTeamLogoFormats}.",
                    );
                }

                // Fallback: check if profile field has any error (for cases where error key format differs)
                if ($errors->has('profile')) {
                    $profileError = $errors->first('profile');
                    if (
                        str_contains($profileError, 'mimes')
                        || str_contains($profileError, 'file')
                        || str_contains($profileError, 'image')
                    ) {
                        return ApiResponseService::validationError(
                            "The profile field must be an image. Allowed formats: {$allowedProfileFormats}.",
                        );
                    }
                }

                return ApiResponseService::validationError($errors->first());
            }

            // Validate required custom form fields for instructor
            // Only validate if platform is 'web'
            // Mobile app (platform 'app') does not support custom fields yet
            $isWeb = strtolower($request->input('platform', 'app')) === 'web';

            if ($isInstructor && $isWeb) {
                // First, get all required custom form fields
                $requiredFields = CustomFormField::where('is_required', 1)->get();

                // Get submitted field IDs from other_details
                $submittedFieldIds = [];
                if ($request->has('other_details') && is_array($request->other_details)) {
                    foreach ($request->other_details as $otherDetail) {
                        if (!isset($otherDetail['id'])) {
                            continue;
                        }

                        $submittedFieldIds[] = $otherDetail['id'];
                    }
                }

                // Check if all required fields are present in the request
                foreach ($requiredFields as $requiredField) {
                    if (in_array($requiredField->id, $submittedFieldIds)) {
                        continue;
                    }

                    return ApiResponseService::validationError("The field '{$requiredField->name}' is required.");
                }

                // Validate that submitted required fields have values
                if ($request->has('other_details') && is_array($request->other_details)) {
                    foreach ($request->other_details as $index => $otherDetail) {
                        if (!isset($otherDetail['id'])) {
                            continue;
                        }

                        $customFormField = CustomFormField::find($otherDetail['id']);

                        if ($customFormField && $customFormField->is_required == 1) {
                            $fieldName = $customFormField->name;
                            $hasValue = false;

                            // Check if field has value based on its type
                            switch ($customFormField->type) {
                                case 'dropdown':
                                case 'radio':
                                case 'checkbox':
                                    // For dropdown, radio, checkbox - check if option_id is provided
                                    if (isset($otherDetail['option_id']) && !empty($otherDetail['option_id'])) {
                                        $hasValue = true;
                                    }
                                    break;

                                case 'file':
                                    // For file - check if file is uploaded
                                    if (
                                        isset($otherDetail['file'])
                                        && $request->hasFile("other_details.{$index}.file")
                                    ) {
                                        $hasValue = true;
                                    }
                                    break;

                                default:
                                    // For text, textarea, number, email - check if value is provided
                                    if (isset($otherDetail['value']) && !empty(trim((string) $otherDetail['value']))) {
                                        $hasValue = true;
                                    }
                                    break;
                            }

                            if (!$hasValue) {
                                return ApiResponseService::validationError("The field '{$fieldName}' is required.");
                            }
                        }
                    }
                }
            }

            // ============ UPDATE USER PROFILE ============
            $userData = [];
            foreach (['name', 'email', 'mobile', 'country_calling_code', 'country_code'] as $field) {
                if ($request->has($field)) {
                    $userData[$field] = $request->input($field);
                }
            }

            // Handle profile image upload
            if ($request->hasFile('profile')) {
                // Delete old profile image if exists
                $oldProfile = $user->getRawOriginal('profile');
                if ($oldProfile && !filter_var($oldProfile, FILTER_VALIDATE_URL)) {
                    Storage::disk('public')->delete($oldProfile);
                }

                $profileImage = $request->file('profile');
                $profileImageName = 'user_profile_' . time() . '.' . $profileImage->getClientOriginalExtension();
                $profileImagePath = $profileImage->storeAs('user_profile', $profileImageName, 'public');
                $userData['profile'] = $profileImagePath;
            }

            $user->update($userData);

            // ============ UPDATE INSTRUCTOR DETAILS (if instructor) ============
            if ($isInstructor) {
                // Check if any instructor-related fields are sent
                $hasInstructorData =
                    $request->has('instructor_type')
                    || $request->has('qualification')
                    || $request->has('years_of_experience')
                    || $request->has('skills')
                    || $request->has('bank_account_number')
                    || $request->has('bank_name')
                    || $request->has('about_me')
                    || $request->has('team_name')
                    || $request->hasFile('team_logo')
                    || $request->hasFile('id_proof')
                    || $request->hasFile('preview_video')
                    || $request->has('social_medias')
                    || $request->has('other_details');

                if ($hasInstructorData) {
                    // Update or Create Instructor Data (only if instructor_type is provided)
                    if ($request->has('instructor_type')) {
                        // Get existing instructor to check current status
                        $existingInstructor = Instructor::where('user_id', $user->id)->first();

                        $instructorData = [
                            'type' => $request->instructor_type,
                        ];

                        // Handle status: preserve approved/suspended status, only change rejected to pending
                        // If status is already approved or suspended, don't change it
                        // Only set status to pending if current status is rejected or if it's a new record
                        if (!$existingInstructor) {
                            // New instructor record - set to pending
                            $instructorData['status'] = 'pending';
                        } elseif ($existingInstructor->status === 'rejected') {
                            // If rejected, change to pending for re-review
                            $instructorData['status'] = 'pending';
                        }
                        // If status is 'approved' or 'suspended', don't include status in $instructorData
                        // so updateOrCreate preserves the existing status

                        $instructor = Instructor::updateOrCreate(['user_id' => $user->id], $instructorData);
                    } else {
                        // Get existing instructor record
                        $instructor = Instructor::where('user_id', $user->id)->first();
                        if (!$instructor) {
                            // If no instructor record exists and no instructor_type provided, skip instructor details update
                            $instructor = null;
                        }
                    }

                    if ($instructor) {
                        // Update Personal Details
                        $instructorPersonalDetail = InstructorPersonalDetail::where(
                            'instructor_id',
                            $instructor->id,
                        )->first();
                        $personalDetailsData = [
                            'qualification' => $request->qualification,
                            'years_of_experience' => $request->years_of_experience,
                            'skills' => $request->skills,
                            'bank_account_number' => $request->bank_account_number,
                            'bank_name' => $request->bank_name,
                            'bank_account_holder_name' => $request->bank_account_holder_name,
                            'bank_ifsc_code' => $request->bank_ifsc_code,
                            'team_name' => $request->team_name,
                            'about_me' => $request->about_me,
                        ];

                        // Handle file uploads
                        $instructorPersonalDetailFolder = 'instructor/personal_details';
                        if ($request->hasFile('team_logo')) {
                            $existingFile = !empty($instructorPersonalDetail)
                                ? $instructorPersonalDetail->getRawOriginal('team_logo')
                                : null;
                            $personalDetailsData['team_logo'] = FileService::compressAndReplace(
                                $request->team_logo,
                                $instructorPersonalDetailFolder,
                                $existingFile,
                            );
                        }
                        if ($request->hasFile('id_proof')) {
                            $existingFile = !empty($instructorPersonalDetail)
                                ? $instructorPersonalDetail->getRawOriginal('id_proof')
                                : null;
                            $personalDetailsData['id_proof'] = FileService::compressAndReplace(
                                $request->id_proof,
                                $instructorPersonalDetailFolder,
                                $existingFile,
                            );
                        }
                        if ($request->hasFile('preview_video')) {
                            $existingFile = !empty($instructorPersonalDetail)
                                ? $instructorPersonalDetail->getRawOriginal('preview_video')
                                : null;
                            $personalDetailsData['preview_video'] = FileService::compressAndReplace(
                                $request->preview_video,
                                $instructorPersonalDetailFolder,
                                $existingFile,
                            );
                        }

                        InstructorPersonalDetail::updateOrCreate([
                            'instructor_id' => $instructor->id,
                        ], $personalDetailsData);

                        // Update Social Media
                        if ($request->has('social_medias') && !empty($request->social_medias)) {
                            $socialMediaData = [];
                            foreach ($request->social_medias as $socialMedia) {
                                if (!(!empty($socialMedia['title']) && !empty($socialMedia['url']))) {
                                    continue;
                                }

                                $socialMediaData[] = [
                                    'instructor_id' => $instructor->id,
                                    'title' => $socialMedia['title'],
                                    'url' => $socialMedia['url'],
                                ];
                            }
                            if (!empty($socialMediaData)) {
                                InstructorSocialMedia::upsert($socialMediaData, ['instructor_id', 'title'], ['url']);
                            }
                        }

                        // Update Other Details
                        if ($request->has('other_details')) {
                            $otherDetailsData = [];
                            $instructorOtherDetailsOptionsFolder = 'instructor/other_details_options';

                            foreach ($request->other_details as $otherDetail) {
                                $customFormField = CustomFormField::find($otherDetail['id']);
                                if (!$customFormField) {
                                    continue;
                                }

                                $baseData = [
                                    'instructor_id' => $instructor->id,
                                    'custom_form_field_id' => $customFormField->id,
                                    'custom_form_field_option_id' => null,
                                    'value' => null,
                                    'extension' => null,
                                ];

                                switch ($customFormField->type) {
                                    case 'dropdown':
                                    case 'checkbox':
                                    case 'radio':
                                        $option = CustomFormFieldOption::where([
                                            'id' => $otherDetail['option_id'] ?? null,
                                            'custom_form_field_id' => $customFormField->id,
                                        ])->first();
                                        if ($option) {
                                            $baseData['custom_form_field_option_id'] = $option->id;
                                            $baseData['value'] = $option->option; // Store option value in value field
                                        }
                                        break;

                                    case 'file':
                                        $fileData = InstructorOtherDetail::where([
                                            'instructor_id' => $instructor->id,
                                            'custom_form_field_id' => $customFormField->id,
                                        ])->first();

                                        $existingFile = null;
                                        if (!empty($fileData)) {
                                            $existingFile = $fileData->getRawOriginal('value');
                                        }

                                        if ($request->hasFile("other_details.{$otherDetail['id']}.file")) {
                                            $baseData['value'] = FileService::compressAndReplace(
                                                $otherDetail['file'],
                                                $instructorOtherDetailsOptionsFolder,
                                                $existingFile,
                                            );
                                            $baseData['extension'] = $otherDetail['file']->getClientOriginalExtension();
                                        }
                                        break;

                                    default:
                                        $baseData['value'] = $otherDetail['value'] ?? null;
                                        break;
                                }

                                $otherDetailsData[] = $baseData;
                            }

                            if (!empty($otherDetailsData)) {
                                InstructorOtherDetail::upsert(
                                    $otherDetailsData,
                                    ['instructor_id', 'custom_form_field_id'],
                                    ['value', 'custom_form_field_option_id', 'extension'],
                                );
                            }
                        }
                    }
                }
            }

            // Refresh user data to get updated profile URL
            $user->refresh();

            // Load same relationships as getUserDetails API
            $user = User::where(['id' => $user->id, 'is_active' => 1])->with([
                'instructor_details.personal_details',
                'instructor_details.social_medias.social_media',
                'instructor_details.other_details.custom_form_field',
                'instructor_details.other_details.custom_form_field_option',
            ])->first();

            // Add same fields as getUserDetails API
            $user['is_instructor'] = $user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));
            $user['instructor_process_status'] = $user->instructor_process_status;

            $responseMessage = $isInstructor
                ? 'Profile and instructor details updated successfully'
                : 'Profile updated successfully';

            return ApiResponseService::successResponse($responseMessage, $user);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'old_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
                'new_password_confirmation' => 'required|string|min:8',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = User::where(['id' => Auth::id(), 'is_active' => 1])->first();
            if (empty($user)) {
                return ApiResponseService::validationError('User not found');
            }

            // Refresh user to ensure we have latest password from database
            $user->refresh();

            // Check if user has a password set
            if (empty($user->password)) {
                return ApiResponseService::validationError(
                    'You cannot change password. Please set a password first using forgot password.',
                );
            }

            // Verify old password (trim to handle whitespace issues)
            $oldPassword = trim($request->old_password);
            if (empty($oldPassword) || !Hash::check($oldPassword, $user->password)) {
                return ApiResponseService::validationError('Old password is incorrect');
            }

            // Check if new password is different from old password
            if (Hash::check($request->new_password, $user->password)) {
                return ApiResponseService::validationError('New password must be different from old password');
            }

            // Update password in database
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            // Update password in Firebase if user has Firebase account
            $socialLogin = SocialLogin::where('user_id', $user->id)->where('type', 'email')->first();
            if ($socialLogin && !empty($socialLogin->firebase_id)) {
                try {
                    HelperService::updateFirebasePassword($socialLogin->firebase_id, $request->new_password);
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    Log::error('Failed to update Firebase password: ' . $e->getMessage());

                    // Continue even if Firebase update fails - database password is updated
                }
            }

            // Revoke all tokens to logout user from all devices
            // User will need to login again with new password
            $user->tokens()->delete();

            return ApiResponseService::successResponse(
                'Password changed successfully. Please login again with your new password.',
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Get user notifications
     */
    public function getUserNotifications(Request $request)
    {
        try {
            $authenticatedUser = Auth::user();

            if (!$authenticatedUser) {
                return ApiResponseService::validationError('User not found');
            }

            // Check if user_team_slug is provided for team notifications
            if ($request->filled('user_team_slug')) {
                // Get the team user by slug
                /** @var User */
                $teamUser = User::where('slug', $request->user_team_slug)->first();
                if (!$teamUser) {
                    return ApiResponseService::validationError('Team user not found');
                }

                // Check if authenticated user is in the same team as the team user
                $authenticatedUserInstructorId = $authenticatedUser->instructor_details->id ?? null;
                $teamUserInstructorId = $teamUser->instructor_details->id ?? null;

                if (!$authenticatedUserInstructorId || !$teamUserInstructorId) {
                    return ApiResponseService::validationError('User or team user is not an instructor');
                }

                // Check if both users are in the same team (either as instructor or team member)
                $isInSameTeam = false;

                // Check if authenticated user is the team user's instructor
                if ($authenticatedUserInstructorId == $teamUserInstructorId) {
                    $isInSameTeam = true;
                } else {
                    // Check if both users are team members of the same instructor
                    $authenticatedUserTeam = \App\Models\TeamMember::where('user_id', $authenticatedUser->id)
                        ->where('status', 'approved')
                        ->first();
                    $teamUserTeam = \App\Models\TeamMember::where('user_id', $teamUser->id)
                        ->where('status', 'approved')
                        ->first();

                    if (
                        $authenticatedUserTeam
                        && $teamUserTeam
                        && $authenticatedUserTeam->instructor_id == $teamUserTeam->instructor_id
                    ) {
                        $isInSameTeam = true;
                    }
                }

                if (!$isInSameTeam) {
                    return ApiResponseService::unauthorizedResponse(
                        'You are not authorized to view this team\'s notifications',
                    );
                }

                // Use team user for notifications
                $user = $teamUser;
            } else {
                // Use authenticated user for notifications
                $user = User::where(['id' => $authenticatedUser->id, 'is_active' => 1])->first();
                if (empty($user)) {
                    return ApiResponseService::validationError('User not found');
                }
            }

            // Get pagination parameters
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);
            $type = $request->get('type', 'all'); // all, global, personal
            $status = $request->get('status', 'all'); // all, read, unread

            // Validate per_page parameter (max 50 records per page)
            if ($perPage > 50) {
                $perPage = 50;
            }

            // Ensure per_page is at least 1 to avoid division by zero
            if ($perPage < 1) {
                $perPage = 10;
            }

            $notifications = collect();

            $getNotificationIcon = static function ($type): string {
                $type = is_string($type) ? $type : 'default';
                return match ($type) {
                    // Courses & learning content
                    'course', 'new_course'                                               => 'fa-book',
                    'certificate', 'certificate_issued'                                  => 'fa-certificate',
                    'exam', 'quiz'                                                       => 'fa-clipboard-list',
                    'assignment', 'homework'                                             => 'fa-file-alt',
                    // Instructors
                    'instructor', 'new_instructor',
                    'instructor_submission', 'instructor_status_update'                  => 'fa-chalkboard-teacher',
                    // Team
                    'team_invitation', 'team_member', 'team_invitation_response'         => 'fa-users',
                    // Wallet & payments
                    'wallet'                                                              => 'fa-wallet',
                    'withdrawal'                                                          => 'fa-money-bill-wave',
                    'commission_paid'                                                     => 'fa-coins',
                    'purchase', 'payment', 'order',
                    'manual_deposit', 'admin_new_manual_deposit'                         => 'fa-money-check-alt',
                    // Subscriptions
                    'subscription', 'manual_subscription_status',
                    'admin_new_subscription_request'                                     => 'fa-id-card',
                    'subscription_expiry'                                                 => 'fa-clock',
                    // Messages
                    'message', 'chat', 'reply'                                           => 'fa-envelope',
                    // Webinars & live sessions
                    'live_class', 'webinar', 'zoom',
                    'webinar_registration', 'webinar_reminder'                           => 'fa-video',
                    // Announcements & system
                    'announcement', 'system', 'global', 'admin_manual'                  => 'fa-bullhorn',
                    // Welcome
                    'welcome'                                                             => 'fa-door-open',
                    // Promotions
                    'promotion', 'offer', 'campaign'                                     => 'fa-gift',
                    // Support
                    'support', 'ticket'                                                  => 'fa-headset',
                    // Reviews
                    'review', 'rating'                                                   => 'fa-star',
                    // Fallback
                    default                                                               => 'fa-bell',
                };
            };

            // Get global notifications (legacy_notifications table)
            if ($type === 'all' || $type === 'global') {
                // Get all read notification IDs for this user
                $readNotificationIds = \App\Models\UserNotificationRead::where('user_id', $user->id)
                    ->pluck('notification_id')
                    ->toArray();

                // Only show notifications sent after user registration date
                $userRegistrationDate = $user->created_at ?? now();

                $globalNotifications = \App\Models\Notification::where('date_sent', '>=', $userRegistrationDate)
                    ->orderBy('date_sent', 'desc')
                    ->get()
                    ->map(static function ($notification) use ($readNotificationIds, $user, $getNotificationIcon) {
                        $slug = null;

                        // Get slug for course or instructor notification types
                        if ($notification->type === 'course' && $notification->type_id) {
                            $course = Course::find($notification->type_id);
                            $slug = $course->slug ?? null;
                        } elseif ($notification->type === 'instructor' && $notification->type_id) {
                            $instructor = Instructor::with('user')->find($notification->type_id);
                            $slug = $instructor->user->slug ?? null;
                        }

                        // Check if this notification is read
                        $isRead = in_array($notification->id, $readNotificationIds);
                        $readRecord = null;
                        if ($isRead) {
                            $readRecord = \App\Models\UserNotificationRead::where('user_id', $user->id)
                                ->where('notification_id', $notification->id)
                                ->first();
                        }

                        return [
                            'id'                => $notification->id,
                            'type'              => 'global',
                            'title'             => $notification->title,
                            'message'           => $notification->message,
                            'notification_type' => $notification->type,
                            'type_id'           => $notification->type_id,
                            'type_link'         => $notification->type_link,
                            'slug'              => $slug,
                            'image'             => $notification->image,
                            'icon'              => $getNotificationIcon($notification->type),
                            'icon_id'           => null,
                            'icon_color'        => null,
                            'date_sent'         => $notification->date_sent,
                            'date_sent_formatted' => $notification->date_sent->format('Y-m-d H:i:s'),
                            'time_ago'          => $notification->date_sent->diffForHumans(),
                            'is_read'           => $isRead,
                            'read_at'           => $readRecord ? $readRecord->read_at->format('Y-m-d H:i:s') : null,
                        ];
                    })
                    ->filter(static function ($notification) use ($status) {
                        // Apply status filter
                        if ($status === 'read') {
                            return $notification['is_read'] === true;
                        } elseif ($status === 'unread') {
                            return $notification['is_read'] === false;
                        }
                        return true; // 'all' - return all notifications
                    });

                $notifications = $notifications->merge($globalNotifications);
            }

            // Get personal notifications (Laravel notifications table)
            if ($type === 'all' || $type === 'personal') {
                // Get personal notifications via the Eloquent relationship
                $personalNotificationsQuery = $user->notifications();

                // Apply status filter
                if ($status === 'read') {
                    $personalNotificationsQuery->whereNotNull('read_at');
                } elseif ($status === 'unread') {
                    $personalNotificationsQuery->whereNull('read_at');
                }

                $personalNotificationsRaw = $personalNotificationsQuery->get();

                // Log for debugging
                Log::info('Personal notifications query result', [
                    'user_id' => $user->id,
                    'count' => $personalNotificationsRaw->count(),
                    'first_notification' => $personalNotificationsRaw->first()
                        ? [
                            'id' => $personalNotificationsRaw->first()?->id,
                            'notifiable_type' => $personalNotificationsRaw->first()?->notifiable_type,
                            'notifiable_id' => $personalNotificationsRaw->first()?->notifiable_id,
                            'data_preview' => substr(json_encode($personalNotificationsRaw->first()?->data ?? []), 0, 100),
                        ] : null,
                ]);

                $personalNotifications = $personalNotificationsRaw->map(static function ($notification) use ($getNotificationIcon) {
                    // Decode data if it's a string (JSON)
                    $data = is_string($notification->data)
                        ? json_decode($notification->data, true)
                        : $notification->data;
                    $data = is_array($data) ? $data : [];
                    $rawType = $data['type'] ?? 'default';
                    $notificationType = is_string($rawType) ? $rawType : 'default';
                    $typeId = $data['type_id'] ?? null;
                    $slug = null;
                    $instructorDetails = null;
                    $teamMembers = [];

                    // Get slug for course or instructor notification types
                    if ($notificationType === 'course' && $typeId) {
                        $course = Course::find($typeId);
                        $slug = $course->slug ?? null;
                    } elseif ($notificationType === 'instructor' && $typeId) {
                        $instructor = Instructor::with('user')->find($typeId);
                        $slug = $instructor->user->slug ?? null;
                    } elseif ($notificationType === 'team_invitation' && $typeId) {
                        // Get team member from type_id
                        $teamMember = \App\Models\TeamMember::with([
                            'instructor.user',
                            'instructor.personal_details',
                            'instructor.social_medias',
                        ])->find($typeId);

                        if ($teamMember && $teamMember->instructor) {
                            $instructor = $teamMember->instructor;

                            // Get instructor details - simplified structure
                            $instructorDetails = [
                                'id' => $instructor->id,
                                'user_id' => $instructor->user_id,
                                'name' => $instructor->user->name ?? '',
                                'slug' => $instructor->user->slug ?? '',
                                'profile' => $instructor->user->profile ?? '',
                            ];

                            // Get only the specific team member (single object, not array)
                            $teamMembers = [
                                'id' => $teamMember->id,
                                'instructor_id' => $teamMember->instructor_id,
                                'user_id' => $teamMember->user_id,
                                'status' => $teamMember->status,
                                'invitation_token' => $teamMember->invitation_token ?? null,
                                'created_at' => $teamMember->created_at
                                    ? $teamMember->created_at->format('Y-m-d H:i:s')
                                    : null,
                                'updated_at' => $teamMember->updated_at
                                    ? $teamMember->updated_at->format('Y-m-d H:i:s')
                                    : null,
                            ];
                        }
                    }

                    // Parse created_at and read_at timestamps
                    $createdAt = $notification->created_at
                        ? (
                            is_string($notification->created_at)
                                ? \Carbon\Carbon::parse($notification->created_at)
                                : $notification->created_at
                        )
                        : now();
                    $readAt = $notification->read_at
                        ? (
                            is_string($notification->read_at)
                                ? \Carbon\Carbon::parse($notification->read_at)
                                : $notification->read_at
                        )
                        : null;

                    $response = [
                        'id'                => $notification->id,
                        'type'              => 'personal',
                        'title'             => $data['title'] ?? 'Notification',
                        'message'           => $data['message'] ?? $data['body'] ?? '',
                        'notification_type' => $notificationType,
                        'type_id'           => $typeId,
                        'type_link'         => $data['type_link'] ?? $data['link'] ?? null,
                        'slug'              => $slug,
                        'image'             => $data['image'] ?? null,
                        'icon'              => $getNotificationIcon($notificationType),
                        'icon_id'           => $data['icon'] ?? null,
                        'icon_color'        => $data['icon_color'] ?? null,
                        'date_sent'         => $createdAt,
                        'date_sent_formatted' => $createdAt->format('Y-m-d H:i:s'),
                        'time_ago'          => $createdAt->diffForHumans(),
                        'is_read'           => !is_null($readAt),
                        'read_at'           => $readAt ? $readAt->format('Y-m-d H:i:s') : null,
                    ];

                    // Add instructor details and team members for team_invitation
                    if ($notificationType === 'team_invitation') {
                        $response['invitation_status'] = $teamMembers['status'] ?? 'pending';
                        $response['instructor_details'] = $instructorDetails;
                        $response['team_members'] = $teamMembers;
                    }

                    return $response;
                });

                // Log before merge
                \Illuminate\Support\Facades\Log::info('Before merge notifications', [
                    'legacy_count' => $notifications->count(),
                    'personal_count' => $personalNotifications->count(),
                    'total_before_merge' => $notifications->count() + $personalNotifications->count(),
                ]);

                $notifications = $notifications->merge($personalNotifications);

                // Log after merge
                \Illuminate\Support\Facades\Log::info('After merge notifications', [
                    'total_count' => $notifications->count(),
                ]);
            }

            // Sort all notifications by date
            $notifications = $notifications->sortByDesc('date_sent');

            // Log before pagination
            \Illuminate\Support\Facades\Log::info('Before pagination', [
                'total_count' => $notifications->count(),
                'sample_notifications' => $notifications
                    ->take(3)
                    ->map(static fn($n) => [
                        'id' => $n['id'] ?? null,
                        'type' => $n['type'] ?? null,
                        'title' => $n['title'] ?? null,
                    ])
                    ->toArray(),
            ]);

            // Apply pagination
            $total = $notifications->count();
            $notifications = $notifications->forPage($page, $perPage)->values()->toArray();

            // Log after pagination
            \Illuminate\Support\Facades\Log::info('After pagination', [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'returned_count' => count($notifications),
                'sample_returned' => array_slice($notifications, 0, 2),
            ]);

            // Get unread count
            // Count personal unread notifications
            $personalUnreadCount = $user->unreadNotifications()->count();

            // Count global unread notifications (if type is 'all' or 'global')
            $globalUnreadCount = 0;
            if ($type === 'all' || $type === 'global') {
                // Only count notifications sent after user registration date
                $userRegistrationDate = $user->created_at ?? now();

                // Get global notification IDs sent after user registration
                $allGlobalNotificationIds = \App\Models\Notification::where('date_sent', '>=', $userRegistrationDate)
                    ->pluck('id')
                    ->toArray();

                // Get read notification IDs for this user
                $readGlobalNotificationIds = \App\Models\UserNotificationRead::where('user_id', $user->id)
                    ->pluck('notification_id')
                    ->toArray();

                // Count unread global notifications
                $globalUnreadCount = count(array_diff($allGlobalNotificationIds, $readGlobalNotificationIds));
            }

            // Total unread count (always return total unread, regardless of status filter)
            $unreadCount = $personalUnreadCount + $globalUnreadCount;

            // Create pagination links
            $lastPage = ceil($total / $perPage);
            $baseUrl = request()->url();
            $path = str_replace(request()->root(), '', $baseUrl);

            // Build query parameters for URLs
            $queryParams = request()->query();
            unset($queryParams['page']); // Remove page from query params

            $firstPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => 1]));
            $lastPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $lastPage]));
            $nextPageUrl = $page < $lastPage
                ? $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $page + 1]))
                : null;
            $prevPageUrl = $page > 1
                ? $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $page - 1]))
                : null;

            // Create pagination links array
            $links = [];

            // Previous link
            $links[] = [
                'url' => $prevPageUrl,
                'label' => '&laquo; Previous',
                'active' => false,
            ];

            // Page number links
            for ($i = 1; $i <= $lastPage; $i++) {
                $pageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $i]));
                $links[] = [
                    'url' => $pageUrl,
                    'label' => (string) $i,
                    'active' => $i == $page,
                ];
            }

            // Next link
            $links[] = [
                'url' => $nextPageUrl,
                'label' => 'Next &raquo;',
                'active' => false,
            ];

            $responseData = [
                'current_page' => (int) $page,
                'data' => $notifications,
                'first_page_url' => $firstPageUrl,
                'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
                'last_page' => $lastPage,
                'last_page_url' => $lastPageUrl,
                'links' => $links,
                'next_page_url' => $nextPageUrl,
                'path' => $path,
                'per_page' => (int) $perPage,
                'prev_page_url' => $prevPageUrl,
                'to' => min($page * $perPage, $total),
                'total' => $total,
                'unread_count' => $unreadCount,
            ];

            return ApiResponseService::successResponse('Notifications retrieved successfully', $responseData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Mark notification as read
     */
    public function markNotificationAsRead(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'notification_id' => 'required',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $authenticatedUser = Auth::user();
            if (!$authenticatedUser) {
                return ApiResponseService::validationError('User not found');
            }

            // Check if user_team_slug is provided for team notifications
            if ($request->filled('user_team_slug')) {
                // Get the team user by slug
                $teamUser = User::where('slug', $request->user_team_slug)->first();
                if (!$teamUser) {
                    return ApiResponseService::validationError('Team user not found');
                }

                // Check if authenticated user is in the same team as the team user
                $authenticatedUserInstructorId = $authenticatedUser->instructor_details->id ?? null;
                $teamUserInstructorId = $teamUser->instructor_details->id ?? null;

                if (!$authenticatedUserInstructorId || !$teamUserInstructorId) {
                    return ApiResponseService::validationError('User or team user is not an instructor');
                }

                // Check if both users are in the same team (either as instructor or team member)
                $isInSameTeam = false;

                // Check if authenticated user is the team user's instructor
                if ($authenticatedUserInstructorId == $teamUserInstructorId) {
                    $isInSameTeam = true;
                } else {
                    // Check if both users are team members of the same instructor
                    $authenticatedUserTeam = \App\Models\TeamMember::where('user_id', $authenticatedUser->id)
                        ->where('status', 'approved')
                        ->first();
                    $teamUserTeam = \App\Models\TeamMember::where('user_id', $teamUser->id)
                        ->where('status', 'approved')
                        ->first();

                    if (
                        $authenticatedUserTeam
                        && $teamUserTeam
                        && $authenticatedUserTeam->instructor_id == $teamUserTeam->instructor_id
                    ) {
                        $isInSameTeam = true;
                    }
                }

                if (!$isInSameTeam) {
                    return ApiResponseService::unauthorizedResponse(
                        'You are not authorized to mark this team\'s notifications as read',
                    );
                }

                // Use team user for notifications
                $user = $teamUser;
            } else {
                // Use authenticated user for notifications
                $user = User::where(['id' => $authenticatedUser->id, 'is_active' => 1])->first();
                if (empty($user)) {
                    return ApiResponseService::validationError('User not found');
                }
            }

            $notificationIds = is_array($request->notification_id) ? $request->notification_id : [$request->notification_id];
            $markedCount = 0;
            $globalCount = 0;
            $errors = [];

            // Process each notification ID
            foreach ($notificationIds as $notificationId) {
                // Convert to string for consistent checking
                $notificationIdStr = (string) $notificationId;

                // Check if it's a personal notification (UUID) or global notification (integer)
                // Personal notifications use UUIDs, global notifications use integer IDs
                if (is_numeric($notificationIdStr) && ctype_digit($notificationIdStr)) {
                    // Global notification (integer ID)
                    $notificationIdInt = (int) $notificationIdStr;

                    // Check if notification exists
                    $globalNotification = \App\Models\Notification::find($notificationIdInt);
                    if ($globalNotification) {
                        // Check if already marked as read
                        $existingRead = \App\Models\UserNotificationRead::where('user_id', $user->id)
                            ->where('notification_id', $notificationIdInt)
                            ->first();

                        if (!$existingRead) {
                            // Mark as read by creating a record in user_notification_reads table
                            try {
                                \App\Models\UserNotificationRead::create([
                                    'user_id' => $user->id,
                                    'notification_id' => $notificationIdInt,
                                    'read_at' => now(),
                                ]);
                                $globalCount++;
                                $markedCount++; // Also increment marked_count for global notifications
                            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                                throw $e;
                            } catch (\Exception $e) {
                                // If duplicate key error, notification is already read
                                if (
                                    str_contains($e->getMessage(), 'Duplicate entry')
                                    || str_contains($e->getMessage(), 'UNIQUE constraint')
                                ) {
                                    $globalCount++;
                                    $markedCount++; // Count as marked even if already read
                                } else {
                                    // Log other errors
                                    \Illuminate\Support\Facades\Log::error('Error marking global notification as read', [
                                        'user_id' => $user->id,
                                        'notification_id' => $notificationIdInt,
                                        'error' => $e->getMessage(),
                                        'trace' => $e->getTraceAsString(),
                                    ]);
                                    $errors[] =
                                        "Failed to mark notification {$notificationIdInt} as read: " . $e->getMessage();
                                }
                            }
                        } else {
                            $globalCount++; // Already read, but count it
                            $markedCount++; // Also count in marked_count
                        }
                    } else {
                        // Notification doesn't exist
                        $errors[] = "Notification ID {$notificationIdInt} not found";
                        \Illuminate\Support\Facades\Log::warning('Global notification not found', [
                            'notification_id' => $notificationIdInt,
                            'user_id' => $user->id,
                            'all_notification_ids' => \App\Models\Notification::pluck('id')->toArray(),
                        ]);
                    }
                } else {
                    // Personal notification (UUID)
                    $notification = $user->notifications()->find($notificationIdStr);
                    if ($notification) {
                        try {
                            $notification->markAsRead();
                            $markedCount++;
                        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                            throw $e;
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error('Error marking personal notification as read', [
                                'user_id' => $user->id,
                                'notification_id' => $notificationIdStr,
                                'error' => $e->getMessage(),
                            ]);
                            $errors[] = 'Failed to mark personal notification as read: ' . $e->getMessage();
                        }
                    } else {
                        $errors[] = "Personal notification ID {$notificationIdStr} not found";
                    }
                }
            }

            $message = 'تم تحديد الإشعارات كمقروءة';
            if ($markedCount > 0) {
                $message = "تم تحديد {$markedCount} إشعار كمقروء";
            } elseif (count($errors) > 0) {
                $message = 'لم يتم تحديد أي إشعارات كمقروءة. ' . implode(' ', $errors);
            }

            $responseData = [
                'marked_count' => $markedCount,
                'global_count' => $globalCount,
                'total_count' => $markedCount, // Total successfully marked notifications
            ];

            if (count($errors) > 0) {
                $responseData['errors'] = $errors;
            }

            return ApiResponseService::successResponse($message, $responseData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllNotificationsAsRead(Request $request)
    {
        try {
            $authenticatedUser = Auth::user();
            if (!$authenticatedUser) {
                return ApiResponseService::validationError('User not found');
            }

            // Check if user_team_slug is provided for team notifications
            if ($request->filled('user_team_slug')) {
                // Get the team user by slug
                $teamUser = User::where('slug', $request->user_team_slug)->first();
                if (!$teamUser) {
                    return ApiResponseService::validationError('Team user not found');
                }

                // Check if authenticated user is in the same team as the team user
                $authenticatedUserInstructorId = $authenticatedUser->instructor_details->id ?? null;
                $teamUserInstructorId = $teamUser->instructor_details->id ?? null;

                if (!$authenticatedUserInstructorId || !$teamUserInstructorId) {
                    return ApiResponseService::validationError('User or team user is not an instructor');
                }

                // Check if both users are in the same team (either as instructor or team member)
                $isInSameTeam = false;

                // Check if authenticated user is the team user's instructor
                if ($authenticatedUserInstructorId == $teamUserInstructorId) {
                    $isInSameTeam = true;
                } else {
                    // Check if both users are team members of the same instructor
                    $authenticatedUserTeam = \App\Models\TeamMember::where('user_id', $authenticatedUser->id)
                        ->where('status', 'approved')
                        ->first();
                    $teamUserTeam = \App\Models\TeamMember::where('user_id', $teamUser->id)
                        ->where('status', 'approved')
                        ->first();

                    if (
                        $authenticatedUserTeam
                        && $teamUserTeam
                        && $authenticatedUserTeam->instructor_id == $teamUserTeam->instructor_id
                    ) {
                        $isInSameTeam = true;
                    }
                }

                if (!$isInSameTeam) {
                    return ApiResponseService::unauthorizedResponse(
                        'You are not authorized to mark this team\'s notifications as read',
                    );
                }

                // Use team user for notifications
                $user = $teamUser;
            } else {
                // Use authenticated user for notifications
                $user = User::where(['id' => $authenticatedUser->id, 'is_active' => 1])->first();
                if (empty($user)) {
                    return ApiResponseService::validationError('User not found');
                }
            }

            // Mark all personal notifications as read
            $personalMarkedCount = $user->unreadNotifications()->count();
            $user->unreadNotifications()->update(['read_at' => now()]);

            // Mark all global notifications as read
            $allGlobalNotifications = \App\Models\Notification::pluck('id')->toArray();
            $alreadyReadGlobalIds = \App\Models\UserNotificationRead::where('user_id', $user->id)
                ->pluck('notification_id')
                ->toArray();

            $unreadGlobalIds = array_diff($allGlobalNotifications, $alreadyReadGlobalIds);
            $globalMarkedCount = 0;

            if (!empty($unreadGlobalIds)) {
                // Bulk insert all unread global notifications
                foreach ($unreadGlobalIds as $notificationId) {
                    // Use firstOrCreate to avoid duplicate key errors
                    \App\Models\UserNotificationRead::firstOrCreate([
                        'user_id' => $user->id,
                        'notification_id' => $notificationId,
                    ], [
                        'read_at' => now(),
                    ]);
                }
                $globalMarkedCount = count($unreadGlobalIds);
            }

            $totalMarked = $personalMarkedCount + $globalMarkedCount;
            $message = 'تم تحديد جميع الإشعارات كمقروءة';
            if ($totalMarked > 0) {
                $message = "تم تحديد {$totalMarked} إشعار كمقروء";
            }

            return ApiResponseService::successResponse($message, [
                'marked_count' => $totalMarked,
                'personal_count' => $personalMarkedCount,
                'global_count' => $globalMarkedCount,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Delete user account (soft delete)
     */
    public function deleteAccount(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'password' => 'nullable|string',
                'firebase_token' => 'nullable|string',
                'confirm_deletion' => 'required|in:true,false,1,0,"1","0",yes,no',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = User::where(['id' => Auth::id(), 'is_active' => 1])->first();
            if (empty($user)) {
                return ApiResponseService::validationError('User not found');
            }

            // Refresh user to ensure we have latest data from database
            $user->refresh();

            // Check if user has money in wallet
            if ($user->wallet_balance > 0) {
                return ApiResponseService::validationError(
                    'You cannot delete your account because you have a remaining balance in your wallet. Please withdraw or spend your funds before deleting your account.',
                );
            }

            // Check if user has confirmed deletion - handle different formats
            $confirmDeletion = $request->confirm_deletion;
            if (is_string($confirmDeletion)) {
                $confirmDeletion = in_array(strtolower($confirmDeletion), ['true', '1', 'yes', '"1"', '"true"']);
            }
            if (!in_array($confirmDeletion, [true, 1, '1', 'true', 'yes'], true)) {
                return ApiResponseService::validationError(
                    'You must confirm that you agree to permanently delete your account and all associated data.',
                );
            }

            // Verify authentication based on user type
            $userType = $user->type;

            if (in_array($userType, ['email', 'mobile'])) {
                // For email and mobile types, password is required
                if (empty($request->password)) {
                    return ApiResponseService::validationError('Password is required to confirm account deletion.');
                }

                // Verify password
                $password = trim((string) $request->password);
                if (empty($password)) {
                    return ApiResponseService::validationError('Password is required to confirm account deletion.');
                }

                if (empty($user->password)) {
                    return ApiResponseService::validationError('Account password not set. Please contact support.');
                }

                if (!Hash::check($password, $user->password)) {
                    return ApiResponseService::validationError(
                        'Incorrect password. Please enter your current password to confirm account deletion.',
                    );
                }
            } elseif (in_array($userType, ['google', 'apple'])) {
                // For google and apple types, firebase_token is required
                if (empty($request->firebase_token)) {
                    return ApiResponseService::validationError(
                        'Firebase token is required to confirm account deletion.',
                    );
                }

                try {
                    // Verify firebase token
                    $verifiedToken = ApiService::verifyFirebaseToken($request->firebase_token);
                    $firebaseId = $verifiedToken->claims()->get('sub');

                    // Verify that the firebase_id matches the user's social login
                    $socialLogin = \App\Models\SocialLogin::where('user_id', $user->id)
                        ->where('type', $userType)
                        ->where('firebase_id', $firebaseId)
                        ->first();

                    if (empty($socialLogin)) {
                        return ApiResponseService::validationError(
                            'Invalid firebase token. Please provide a valid token to confirm account deletion.',
                        );
                    }
                } catch (\Throwable) {
                    return ApiResponseService::validationError(
                        'Invalid firebase token. Please provide a valid token to confirm account deletion.',
                    );
                }
            } else {
                // Unknown type or null type - fallback to password check if available
                if (!empty($user->password)) {
                    if (empty($request->password)) {
                        return ApiResponseService::validationError('Password is required to confirm account deletion.');
                    }

                    $password = trim((string) $request->password);
                    if (empty($password) || !Hash::check($password, $user->password)) {
                        return ApiResponseService::validationError(
                            'Incorrect password. Please enter your current password to confirm account deletion.',
                        );
                    }
                } else {
                    return ApiResponseService::validationError(
                        'Unable to verify account deletion. Please contact support.',
                    );
                }
            }

            // Delete Firebase user account if user is google/apple type
            if (in_array($userType, ['google', 'apple'])) {
                try {
                    // Get firebase_id from social_logins table before deleting
                    $socialLogins = \App\Models\SocialLogin::where('user_id', $user->id)->where(
                        'type',
                        $userType,
                    )->get();

                    foreach ($socialLogins as $socialLogin) {
                        if (empty($socialLogin->firebase_id)) {
                            continue;
                        }

                        try {
                            // Delete user from Firebase
                            \App\Services\HelperService::removeUserFromFirebase($socialLogin->firebase_id);
                        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                            throw $e;
                        } catch (\Throwable $firebaseError) {
                            // Log Firebase deletion error but continue with database deletion
                            Log::warning('Failed to delete Firebase user during account deletion', [
                                'user_id' => $user->id,
                                'firebase_id' => $socialLogin->firebase_id,
                                'error' => $firebaseError->getMessage(),
                            ]);
                        }
                    }
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // Log error but continue with database deletion
                    Log::warning('Error during Firebase account deletion', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Start database transaction
            DB::beginTransaction();

            try {
                // Soft delete user account (set deleted_at and is_active)
                // Use forceFill to bypass mass assignment protection for deleted_at
                $user->forceFill([
                    'deleted_at' => now(),
                    'is_active' => 0,
                ])->save();

                // Delete user's personal notifications
                $user->notifications()->delete();

                // Delete user's course enrollments and progress
                \App\Models\Course\UserCourseTrack::where('user_id', $user->id)->delete();
                \App\Models\UserCurriculumTracking::where('user_id', $user->id)->delete();

                // Delete user's quiz attempts
                \App\Models\Course\CourseChapter\Quiz\UserQuizAttempt::where('user_id', $user->id)->delete();

                // Delete user's assignment submissions
                \App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission::where(
                    'user_id',
                    $user->id,
                )->delete();

                // Delete user's wishlist items
                \App\Models\Wishlist::where('user_id', $user->id)->delete();

                // Delete user's cart items
                \App\Models\Cart::where('user_id', $user->id)->delete();

                // Delete user's orders (orders table doesn't have soft deletes)
                \App\Models\Order::where('user_id', $user->id)->delete();

                // Delete user's ratings
                \App\Models\Rating::where('user_id', $user->id)->delete();

                // Delete user's search history
                \App\Models\SearchHistory::where('user_id', $user->id)->delete();

                // Delete user's FCM tokens
                \App\Models\UserFcmToken::where('user_id', $user->id)->delete();

                // Delete user's wallet history
                \App\Models\WalletHistory::where('user_id', $user->id)->delete();

                // Delete user's social login records
                \App\Models\SocialLogin::where('user_id', $user->id)->delete();

                // Note: social_medias table is a master table (doesn't have user_id)
                // User-specific social media links are deleted via instructor_social_medias
                // which is handled in the instructor section below

                // Delete user's team memberships
                \App\Models\TeamMember::where('user_id', $user->id)->delete();

                // Delete user's withdrawal requests
                \App\Models\WithdrawalRequest::where('user_id', $user->id)->delete();

                // Delete user's refund requests
                \App\Models\RefundRequest::where('user_id', $user->id)->delete();

                // Delete user's course discussions and replies
                \App\Models\Course\CourseDiscussion::where('user_id', $user->id)->delete();

                // If user is an instructor, handle instructor-specific data
                if ($user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                    // Get instructor record
                    $instructor = \App\Models\Instructor::where('user_id', $user->id)->first();

                    if ($instructor) {
                        // Soft delete instructor's courses (courses are linked via user_id, not instructor_id)
                        \App\Models\Course\Course::where('user_id', $user->id)->update(['deleted_at' => now()]);

                        // Also remove instructor from course_instructors pivot table
                        \App\Models\CourseInstructor::where('user_id', $user->id)->delete();

                        // Delete instructor's personal details
                        \App\Models\InstructorPersonalDetail::where('instructor_id', $instructor->id)->delete();
                        \App\Models\InstructorOtherDetail::where('instructor_id', $instructor->id)->delete();
                        \App\Models\InstructorSocialMedia::where('instructor_id', $instructor->id)->delete();

                        // Soft delete instructor record
                        $instructor->update(['deleted_at' => now()]);
                    }
                }

                // Commit transaction
                DB::commit();

                // Revoke all tokens for this user
                $user->tokens()->delete();

                return ApiResponseService::successResponse(
                    'Your account has been successfully deleted. All your data has been removed from our system.',
                );
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                // Rollback transaction on error
                DB::rollback();
                throw $e;
            }
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            // Log the actual error for debugging
            Log::error('Delete account error: ' . $th->getMessage(), [
                'user_id' => Auth::id(),
                'trace' => $th->getTraceAsString(),
            ]);
            return ApiResponseService::errorResponse(
                'Failed to delete account. Please try again later.',
                exception: $th,
            );
        }
    }

    /**
     * Get user's support center conversations
     */
    public function getMyContactMessages(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthenticated', 401);
        }

        $perPage = (int) $request->input('per_page', 15);
        
        $messages = \App\Models\ContactMessage::where('user_id', $user->id)
            ->withCount(['replies as unread_count' => function ($query) {
                $query->where('sender_type', 'admin')->where('is_read', false);
            }])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Format for the frontend requirement
        $formatted = $messages->map(function ($msg) {
            // Get the last reply time or creation time
            $lastReply = \App\Models\ContactMessageReply::where('contact_message_id', $msg->id)
                ->orderBy('created_at', 'desc')
                ->first();
                
            return [
                'id' => $msg->id,
                'subject' => \Illuminate\Support\Str::limit($msg->message, 50),
                'status' => $msg->status,
                'unread_count' => $msg->unread_count,
                'created_at' => $msg->created_at,
                'last_reply_at' => $lastReply ? $lastReply->created_at : clone $msg->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
            ]
        ]);
    }

    /**
     * Get a single conversation thread
     */
    public function getContactMessageThread(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthenticated', 401);
        }

        $message = \App\Models\ContactMessage::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$message) {
            return ApiResponseService::errorResponse('Conversation not found', 404);
        }

        // Mark unread admin replies as read
        \App\Models\ContactMessageReply::where('contact_message_id', $message->id)
            ->where('sender_type', 'admin')
            ->where('is_read', false)
            ->update(['is_read' => true]);

        // Get replies
        $replies = \App\Models\ContactMessageReply::where('contact_message_id', $message->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $conversation = [];
        
        // Add the initial message as the first item in the conversation
        $conversation[] = [
            'id' => 0, // Using 0 or a virtual ID for the parent message
            'sender' => 'user',
            'message' => $message->message,
            'created_at' => $message->created_at,
        ];

        // If there's a legacy reply_message on the parent, add it
        if ($message->reply_message) {
            $conversation[] = [
                'id' => -1,
                'sender' => 'admin',
                'message' => $message->reply_message,
                'created_at' => $message->updated_at,
            ];
        }

        foreach ($replies as $reply) {
            $conversation[] = [
                'id' => $reply->id,
                'sender' => $reply->sender_type,
                'message' => $reply->message,
                'created_at' => $reply->created_at,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $message->id,
                'subject' => \Illuminate\Support\Str::limit($message->message, 50),
                'status' => $message->status,
                'conversation' => $conversation,
            ]
        ]);
    }

    /**
     * Reply to a conversation
     */
    public function replyContactMessage(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthenticated', 401);
        }

        $message = \App\Models\ContactMessage::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$message) {
            return ApiResponseService::errorResponse('Conversation not found', 404);
        }

        if (in_array($message->status, ['closed', 'completed'])) {
            return ApiResponseService::errorResponse('Cannot reply to a closed conversation', 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:2',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        \App\Models\ContactMessageReply::create([
            'contact_message_id' => $message->id,
            'user_id' => $user->id,
            'sender_type' => 'user',
            'message' => $request->message,
        ]);

        $message->update(['status' => 'waiting_admin']);

        \App\Models\UserNotification::create([
            'user_id' => $user->id,
            'type' => 'support_message',
            'title' => 'تم إرسال ردك',
            'message' => 'تم إرسال ردك لفريق الدعم',
            'url' => '/contact-us?tab=conversations',
        ]);

        // Optional: Notify admin here

        return response()->json(['success' => true]);
    }

    /**
     * Submit contact us form
     */
    public function submitContactForm(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'email'      => 'required|email|max:255',
                'message'    => 'required|string|max:2000',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Detect authenticated user (optional — guests may also send)
            $authUser = Auth::user();

            // Save to database
            $contactMessage = \App\Models\ContactMessage::create([
                'user_id'    => $authUser?->id,
                'first_name' => $request->first_name,
                'email'      => $request->email,
                'message'    => $request->message,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status'     => 'new',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($authUser) {
                \App\Models\UserNotification::create([
                    'user_id' => $authUser->id,
                    'type' => 'support_message',
                    'title' => 'تم إرسال رسالتك',
                    'message' => 'تم استلام رسالتك وسيتم الرد عليك قريبًا',
                    'url' => '/contact-us?tab=conversations',
                ]);
            }

            $appName = \App\Services\HelperService::systemSettings('app_name') ?? 'LMS';

            // 🔔 Notify all admins (in-app + FCM push)
            try {
                $admins = \App\Models\User::role(config('constants.SYSTEM_ROLES.SUPER_ADMIN', 'Super Admin'))
                    ->where('is_active', 1)
                    ->get();

                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\AdminNewContactMessageNotification(
                        $contactMessage,
                        $appName,
                    ));
                }
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                Log::error('submitContactForm: Failed to notify admins', [
                    'contact_message_id' => $contactMessage->id,
                    'error'              => $e->getMessage(),
                ]);
            }

            // Send email notification to admin
            try {
                $adminEmail = \App\Services\HelperService::systemSettings('admin_email');
                if (empty($adminEmail)) {
                    $adminEmail = 'admin@example.com';
                }

                Mail::queue(
                    'emails.contact-form',
                    [
                        'contactMessage' => $contactMessage,
                        'appName'        => $appName,
                    ],
                    static function ($message) use ($adminEmail, $appName, $contactMessage): void {
                        $message->to($adminEmail)->subject('New Contact Form Submission - ' . $appName)->replyTo(
                            $contactMessage->email,
                            $contactMessage->first_name,
                        );
                    },
                );
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                Log::error('Failed to send contact form email: ' . $e->getMessage());
                // Don't fail the request if email fails
            }

            // Log the contact message
            Log::info('Contact form submission saved:', [
                'id'           => $contactMessage->id,
                'user_id'      => $contactMessage->user_id,
                'first_name'   => $contactMessage->first_name,
                'email'        => $contactMessage->email,
                'ip_address'   => $contactMessage->ip_address,
                'submitted_at' => $contactMessage->created_at,
            ]);

            return ApiResponseService::successResponse(
                'Your message has been sent successfully! We will get back to you soon.',
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Failed to send message. Please try again later.', exception: $th);
        }
    }

    // this method get all categories
    public function getCategories(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'nullable|exists:categories,id',
                'slug' => 'nullable|exists:categories,slug',
                'get_subcategory' => 'nullable|boolean',
                'get_parent_category' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'per_page' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                ApiResponseService::validationError($validator->errors()->first());
            }

            $categoryQuery = Category::select(
                'id',
                'name',
                'image',
                'parent_category_id',
                'description',
                'status',
                'slug',
                'sequence',
                'is_featured',
            )
                ->withCount(['subcategories' => static function ($q): void {
                    $q->where('status', 1);
                }])
                ->withCount(['parent_category' => static function ($q): void {
                    $q->where('status', 1);
                }])
                ->selectRaw('(SELECT COUNT(DISTINCT courses.id) FROM courses
                        WHERE courses.category_id IN (
                            SELECT cat.id FROM categories cat
                            WHERE cat.id = categories.id
                            OR cat.parent_category_id = categories.id
                            OR cat.parent_category_id IN (
                                SELECT subcat.id FROM categories subcat
                                WHERE subcat.parent_category_id = categories.id
                            )
                        )
                        AND courses.is_active = 1
                        AND courses.status = "publish"
                        AND courses.approval_status = "approved"
                        AND courses.deleted_at IS NULL
                        AND EXISTS (
                            SELECT 1 FROM course_chapters
                            WHERE course_chapters.course_id = courses.id
                            AND course_chapters.is_active = 1
                            AND course_chapters.deleted_at IS NULL
                            AND (
                                EXISTS (SELECT 1 FROM course_chapter_lectures WHERE course_chapter_lectures.course_chapter_id = course_chapters.id AND course_chapter_lectures.is_active = 1 AND course_chapter_lectures.deleted_at IS NULL)
                                OR EXISTS (SELECT 1 FROM course_chapter_quizzes WHERE course_chapter_quizzes.course_chapter_id = course_chapters.id AND course_chapter_quizzes.is_active = 1 AND course_chapter_quizzes.deleted_at IS NULL)
                                OR EXISTS (SELECT 1 FROM course_chapter_assignments WHERE course_chapter_assignments.course_chapter_id = course_chapters.id AND course_chapter_assignments.is_active = 1 AND course_chapter_assignments.deleted_at IS NULL)
                                OR EXISTS (SELECT 1 FROM course_chapter_resources WHERE course_chapter_resources.course_chapter_id = course_chapters.id AND course_chapter_resources.is_active = 1 AND course_chapter_resources.deleted_at IS NULL)
                            )
                        )) as courses_count')
                ->where('status', 1)
                ->when(static function ($query) use ($request): void {
                    if ($request->has('id')) {
                        $query->where('id', $request->id);
                        if ($request->has('get_subcategory') && $request->get_subcategory == 1) {
                            $query->with(['subcategories' => static function ($subQuery): void {
                                $subQuery->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')->orderBy(
                                    'sequence',
                                    'ASC',
                                );
                            }]);
                        } else if ($request->has('get_parent_category') && $request->get_parent_category == 1) {
                            $query->with('parent_category');
                        }
                    } else if ($request->has('slug')) {
                        $query->where('slug', $request->slug);
                        if ($request->has('get_subcategory') && $request->get_subcategory == 1) {
                            $query->with(['subcategories' => static function ($subQuery): void {
                                $subQuery->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')->orderBy(
                                    'sequence',
                                    'ASC',
                                );
                            }]);
                        } else if ($request->has('get_parent_category') && $request->get_parent_category == 1) {
                            $query->with('parent_category');
                        }
                    } else if (!$request->has('is_featured')) {
                        $query->whereNull('parent_category_id');
                    }

                    if ($request->has('is_featured')) {
                        $query->where('is_featured', $request->boolean('is_featured'));
                    }
                })
                ->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')
                ->orderBy('sequence', 'ASC');

            // Get paginated results
            $perPage = $request->per_page ?? 15;
            $categories = $categoryQuery->paginate($perPage);

            // Load first 2 courses for each category (recursive)
            $categories->getCollection()->transform(function ($category) {
                // Get IDs of this category and its subcategories (up to 2 levels deep)
                $categoryIds = \App\Models\Category::where('id', $category->id)
                    ->orWhere('parent_category_id', $category->id)
                    ->orWhereIn('parent_category_id', function ($query) use ($category) {
                        $query->select('id')->from('categories')->where('parent_category_id', $category->id);
                    })
                    ->pluck('id');

                $category->courses = \App\Models\Course\Course::whereIn('category_id', $categoryIds)
                    ->where('is_active', 1)
                    ->where('status', 'publish')
                    ->where('approval_status', 'approved')
                    ->select('id', 'title', 'thumbnail', 'short_description', 'category_id', 'intro_video', 'intro_video_type')
                    ->take(2)
                    ->get()
                    ->map(function ($course) use ($category) {
                        return [
                            'id' => $course->id,
                            'title' => $course->title,
                            'image' => $course->thumbnail, // Accessor handles URL
                            'description' => $course->short_description,
                            'intro_video' => $course->intro_video, // Accessor handles URL
                            'category' => $category->name,
                        ];
                    });
                return $category;
            });

            if ($request->has('is_featured')) {
                if ($categories->isEmpty()) {
                    return response()->json(['data' => []]);
                }
                
                return response()->json([
                    'data' => $categories->getCollection()->values()->toArray(),
                    'meta' => [
                        'current_page' => $categories->currentPage(),
                        'last_page' => $categories->lastPage(),
                        'per_page' => $categories->perPage(),
                        'total' => $categories->total(),
                    ],
                ]);
            }

            if ($categories->isEmpty()) {
                return response()->json(['data' => []]);
            }

            return ApiResponseService::successResponse('Categories retrieved successfully', $categories);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    public function getSubCategories(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|exists:categories,id',
            'slug' => 'nullable|exists:categories,slug',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            // get categories with subcategory
            $categoryQuery = Category::select(
                'id',
                'name',
                'image',
                'parent_category_id',
                'description',
                'status',
                'slug',
            )
                ->withCount(['subcategories' => static function ($q): void {
                    $q->where('status', 1); // sub with active
                }])
                ->where(['status' => 1])
                ->orderBy('sequence', 'ASC')
                ->when(static function ($query) use ($request): void {
                    if ($request->has('category_id')) {
                        $query->where('parent_category_id', $request->category_id);
                    }
                    if ($request->has('slug')) {
                        $query->where('slug', $request->slug);
                    }
                }, static function ($query): void {
                    $query->whereNull('parent_category_id');
                })
                ->with([
                    // subcategories (level 1)
                    'subcategories' => static function ($query): void {
                        $query
                            ->select(
                                'id',
                                'sequence',
                                'name',
                                'image',
                                'parent_category_id',
                                'description',
                                'status',
                                'slug',
                            )
                            ->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->withCount(['subcategories' => static function ($q): void {
                                $q->where('status', 1);
                            }]);
                    },
                    //subcategories (level 2) - subcategories of subcategories
                    'subcategories.subcategories' => static function ($query): void {
                        $query
                            ->select(
                                'id',
                                'sequence',
                                'name',
                                'image',
                                'parent_category_id',
                                'description',
                                'status',
                                'slug',
                            )
                            ->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->withCount(['subcategories' => static function ($q): void {
                                $q->where('status', 1);
                            }]);
                    },
                    //subcategories (level 3) - subcategories of subcategories of subcategories
                    'subcategories.subcategories.subcategories' => static function ($query): void {
                        $query
                            ->select(
                                'id',
                                'sequence',
                                'name',
                                'image',
                                'parent_category_id',
                                'description',
                                'status',
                                'slug',
                            )
                            ->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->withCount(['subcategories' => static function ($q): void {
                                $q->where('status', 1);
                            }]);
                    },
                ]);

            // Get paginated results
            $perPage = $request->per_page ?? 15;
            $subcategories = $categoryQuery->paginate($perPage);
            ApiResponseService::successResponse(null, $subcategories);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    public function getCustomFormFields(Request $request)
    {
        try {
            $customFormFields = CustomFormField::select('id', 'name', 'type', 'is_required', 'sort_order')
                ->whereNull('deleted_at') // Only get active (non-deleted) fields
                ->with(['options' => static function ($query): void {
                    $query->select('id', 'custom_form_field_id', 'option')->whereNull('deleted_at'); // Only get active (non-deleted) options
                }])
                ->orderBy('sort_order')
                ->get();
            ApiResponseService::successResponse('Data Fetched Successfully', $customFormFields);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller ->getCustomFormFields');
            ApiResponseService::errorResponse();
        }
    }

    public function removeUser(Request $request)
    {
        ApiService::validateRequest($request, [
            'firebase_token' => 'nullable',
        ]);
        try {
            $firebaseId = null;
            if ($request->has('firebase_token')) {
                $firebaseId = ApiService::removeUserFromFirebase($request->firebase_token);
            }
            $userID = SocialLogin::where('firebase_id', $firebaseId)->first();
            if (!empty($userID)) {
                $user = User::find($userID->user_id);
                if (!empty($user)) {
                    $user->forceDelete();
                }
            }
            ApiResponseService::successResponse('User removed successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }

    public function getAppSettings(Request $request)
    {
        try {
            $generalSystemSettings = ApiService::getGeneralSystemSettings();
            $appSettings = HelperService::systemSettings([
                'playstore_url',
                'appstore_url',
                'android_version',
                'ios_version',
                'app_version',
                'maintaince_mode',
                'force_update',
                'app_name',
                'website_url',
                'announcement_bar',
                'favicon',
                'vertical_logo',
                'horizontal_logo',
                'placeholder_image',
                'contact_address',
                'contact_email',
                'contact_phone',
            ]);

            // Convert file paths to full URLs (only if not already a URL)
            $fileFields = ['favicon', 'vertical_logo', 'horizontal_logo', 'placeholder_image'];
            foreach ($fileFields as $field) {
                if (!(!empty($appSettings[$field]) && !filter_var($appSettings[$field], FILTER_VALIDATE_URL))) {
                    continue;
                }

                $appSettings[$field] = FileService::getFileUrl($appSettings[$field]);
            }

            // Get default language
            $defaultLanguage = Language::where('status', 1)->where('is_default', true)->first();

            // If no default language found, try to get English, otherwise get first active language
            if (!$defaultLanguage) {
                $defaultLanguage = Language::where('status', 1)->where('code', 'en')->first();
            }

            if (!$defaultLanguage) {
                $defaultLanguage = Language::where('status', 1)->first();
            }

            // Add default language id and code to app settings
            if ($defaultLanguage) {
                $appSettings['default_language_id'] = $defaultLanguage->id;
                $appSettings['default_language_code'] = $defaultLanguage->code;
            } else {
                $appSettings['default_language_id'] = null;
                $appSettings['default_language_code'] = 'EN';
            }

            $appSettings = array_merge($generalSystemSettings, $appSettings);
            ApiResponseService::successResponse('Data Fetched Successfully', $appSettings);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }

    public function getWebSettings(Request $request)
    {
        try {
            $generalSystemSettings = ApiService::getGeneralSystemSettings();
            $webSettings = HelperService::systemSettings([
                'individual_instructor_terms',
                'team_instructor_terms',
                'app_name',
                'website_url',
                'announcement_bar',
                'playstore_url',
                'appstore_url',
                'favicon',
                'vertical_logo',
                'horizontal_logo',
                'placeholder_image',
                'contact_address',
                'contact_email',
                'contact_phone',
                'schema',
                'system_light_colour',
                'maintaince_mode',
                'hover_color',
                'footer_description',
                'website_copyright',
            ]);

            // Convert file paths to full URLs (only if not already a URL)
            $fileFields = ['favicon', 'vertical_logo', 'horizontal_logo', 'placeholder_image'];
            foreach ($fileFields as $field) {
                if (!(!empty($webSettings[$field]) && !filter_var($webSettings[$field], FILTER_VALIDATE_URL))) {
                    continue;
                }

                $webSettings[$field] = FileService::getFileUrl($webSettings[$field]);
            }

            // Process copyright to replace {year} placeholder
            $webSettings['website_copyright'] = HelperService::getCopyright();

            $socialMedia = SocialMedia::select('id', 'name', 'icon', 'url')->get();
            $webSettings = array_merge($generalSystemSettings, $webSettings, ['social_media' => $socialMedia]);
            ApiResponseService::successResponse('Data Fetched Successfully', $webSettings);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }

    public function getWhyChooseUs(Request $request)
    {
        try {
            $whyChooseUsSettings = HelperService::systemSettings([
                'why_choose_us_title',
                'why_choose_us_description',
                'why_choose_us_point_1',
                'why_choose_us_point_2',
                'why_choose_us_point_3',
                'why_choose_us_point_4',
                'why_choose_us_point_5',
                'why_choose_us_image',
                'why_choose_us_button_text',
                'why_choose_us_button_link',
            ]);

            // Format the response for better structure
            $formattedData = [
                'title' => $whyChooseUsSettings['why_choose_us_title'] ?? '',
                'description' => $whyChooseUsSettings['why_choose_us_description'] ?? '',
                'image' => $whyChooseUsSettings['why_choose_us_image'] ?? null,
                'button_text' => $whyChooseUsSettings['why_choose_us_button_text'] ?? '',
                'button_link' => $whyChooseUsSettings['why_choose_us_button_link'] ?? '',
                'points' => [
                    $whyChooseUsSettings['why_choose_us_point_1'] ?? '',
                    $whyChooseUsSettings['why_choose_us_point_2'] ?? '',
                    $whyChooseUsSettings['why_choose_us_point_3'] ?? '',
                    $whyChooseUsSettings['why_choose_us_point_4'] ?? '',
                    $whyChooseUsSettings['why_choose_us_point_5'] ?? '',
                ],
            ];

            return ApiResponseService::successResponse('Why Choose Us data fetched successfully', $formattedData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getWhyChooseUs Method');
            return ApiResponseService::errorResponse('Failed to retrieve Why Choose Us data');
        }
    }

    public function getBecomeInstructor(Request $request)
    {
        try {
            // In single instructor mode, return empty data
            if (\App\Services\InstructorModeService::isSingleInstructorMode()) {
                return ApiResponseService::successResponse('Become Instructor is disabled in Single Instructor mode', [
                    'title' => '',
                    'description' => '',
                    'button_text' => '',
                    'button_link' => '',
                    'steps' => [],
                ]);
            }

            $becomeInstructorSettings = HelperService::systemSettings([
                'become_instructor_title',
                'become_instructor_description',
                'become_instructor_button_text',
                'become_instructor_button_link',
                'become_instructor_step_1_title',
                'become_instructor_step_1_description',
                'become_instructor_step_1_image',
                'become_instructor_step_2_title',
                'become_instructor_step_2_description',
                'become_instructor_step_2_image',
                'become_instructor_step_3_title',
                'become_instructor_step_3_description',
                'become_instructor_step_3_image',
                'become_instructor_step_4_title',
                'become_instructor_step_4_description',
                'become_instructor_step_4_image',
            ]);

            // Format the response for better structure
            $formattedData = [
                'title' => $becomeInstructorSettings['become_instructor_title'] ?? '',
                'description' => $becomeInstructorSettings['become_instructor_description'] ?? '',
                'button_text' => $becomeInstructorSettings['become_instructor_button_text'] ?? '',
                'button_link' => $becomeInstructorSettings['become_instructor_button_link'] ?? '',
                'steps' => [
                    [
                        'step' => 1,
                        'title' => $becomeInstructorSettings['become_instructor_step_1_title'] ?? '',
                        'description' => $becomeInstructorSettings['become_instructor_step_1_description'] ?? '',
                        'image' => $becomeInstructorSettings['become_instructor_step_1_image'] ?? null,
                    ],
                    [
                        'step' => 2,
                        'title' => $becomeInstructorSettings['become_instructor_step_2_title'] ?? '',
                        'description' => $becomeInstructorSettings['become_instructor_step_2_description'] ?? '',
                        'image' => $becomeInstructorSettings['become_instructor_step_2_image'] ?? null,
                    ],
                    [
                        'step' => 3,
                        'title' => $becomeInstructorSettings['become_instructor_step_3_title'] ?? '',
                        'description' => $becomeInstructorSettings['become_instructor_step_3_description'] ?? '',
                        'image' => $becomeInstructorSettings['become_instructor_step_3_image'] ?? null,
                    ],
                    [
                        'step' => 4,
                        'title' => $becomeInstructorSettings['become_instructor_step_4_title'] ?? '',
                        'description' => $becomeInstructorSettings['become_instructor_step_4_description'] ?? '',
                        'image' => $becomeInstructorSettings['become_instructor_step_4_image'] ?? null,
                    ],
                ],
            ];

            return ApiResponseService::successResponse('Become Instructor data fetched successfully', $formattedData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getBecomeInstructor Method');
            return ApiResponseService::errorResponse('Failed to retrieve Become Instructor data');
        }
    }

    public function getPaymentIntent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_id' => 'required',
            'platform_type' => 'required|in:app,web',
        ]);
        if ($validator->fails()) {
            ApiResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $paymentSettings = HelperService::getActivePaymentDetails();
            if (empty($paymentSettings)) {
                ApiResponseService::validationError('None of payment method is activated');
            }

            $course = Course::where(['id' => $request->course_id, 'course_type' => 'paid', 'is_active' => 1])->first();
            if (empty($course)) {
                ApiResponseService::validationError('No course found');
            }

            $purchasedCourse = UserCourseTrack::where([
                'user_id' => Auth::user()?->id,
                'course_id' => $request->course_id,
            ])->first();
            if (!empty($purchasedCourse)) {
                ApiResponseService::validationError('You already have purchased this course');
            }

            //Add Payment Data to Payment Transactions Table
            $paymentTransactionData = PaymentTransaction::create([
                'user_id' => Auth::user()?->id,
                'course_id' => $request->course_id,
                'amount' => !empty($course->discounted_price) ? $course->discounted_price : $course->price,
                'payment_gateway' => $paymentSettings['payment_method'],
                'payment_status' => 'pending',
                'order_id' => null,
                'payment_type' => 'online',
            ]);

            $paymentIntent = PaymentService::create($paymentSettings)->createAndFormatPaymentIntent(
                round($course->price, 2),
                [
                    'payment_transaction_id' => $paymentTransactionData->id,
                    'course_id' => $course->id,
                    'user_id' => Auth::user()?->id,
                    'email' => Auth::user()?->email,
                    'platform_type' => $request->platform_type,
                    'description' => $request->description ?? $course->title,
                    'user_name' => Auth::user()->name ?? '',
                    'address_line1' => Auth::user()->address ?? '',
                    'address_city' => Auth::user()->city ?? '',
                ],
            );
            $paymentTransactionData->update(['order_id' => $paymentIntent['id']]);

            $paymentTransactionData = PaymentTransaction::findOrFail($paymentTransactionData->id);
            // Custom Array to Show as response
            $paymentGatewayDetails = [
                ...$paymentIntent,
                'payment_transaction_id' => $paymentTransactionData->id,
            ];

            DB::commit();
            ApiResponseService::successResponse('', [
                'payment_intent' => $paymentGatewayDetails,
                'payment_transaction' => $paymentTransactionData,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();
            ApiResponseService::logErrorResponse($e);
            ApiResponseService::errorResponse();
        }
    }

    public function getSystemLanguages(Request $request)
    {
        $systemType = $request->get('system_type'); // app | web | null
        $code = $request->get('code'); // optional

        if ($code) {
            // 🔹 Only requested language
            $language = Language::where('status', 1)->where('code', $code)->first();

            if (!$language) {
                return ApiResponseService::errorResponse('Language not found', [], 404);
            }

            $langData = [
                'id' => $language->id,
                'name' => $language->name,
                'code' => $language->code,
                'is_rtl' => (bool) $language->rtl,
                'is_default' => (bool) $language->is_default,
                'image' => $language->image,
            ];

            // system_type = app
            if ($systemType === 'app') {
                $file_app = resource_path("lang/{$language->code}_app.json");
                $langData['translations_app'] = File::exists($file_app) ? json_decode(File::get($file_app), true) : [];
            }
            // system_type = web
            elseif ($systemType === 'web') {
                $file_web = resource_path("lang/{$language->code}_web.json");
                $langData['translations_web'] = File::exists($file_web) ? json_decode(File::get($file_web), true) : [];
            }
            // system_type = null → include both
            else {
                $file_app = resource_path("lang/{$language->code}_app.json");
                $file_web = resource_path("lang/{$language->code}_web.json");

                $langData['translations_app'] = File::exists($file_app) ? json_decode(File::get($file_app), true) : [];

                $langData['translations_web'] = File::exists($file_web) ? json_decode(File::get($file_web), true) : [];
            }

            $result = [
                'languages' => [$langData],
            ];

            return ApiResponseService::successResponse('Language Fetched Successfully', $result);
        }

        // 🔹 If no code → fetch all
        $languages = Language::where('status', 1)->get();
        if ($languages->isEmpty()) {
            return ApiResponseService::errorResponse('No language found', [], 404);
        }

        // 🔹 Find default language
        $defaultLang =
            Language::where('status', 1)->where('is_default', true)->first() ?? Language::where('status', 1)
                ->where('code', 'en')
                ->first() ?? $languages->first();

        // 🔹 Prepare languages list with empty translations
        $formattedLanguages = $languages->map(static function ($language) use ($systemType) {
            $lang = [
                'id' => $language->id,
                'name' => $language->name,
                'code' => $language->code,
                'is_rtl' => (bool) $language->rtl,
                'is_default' => (bool) $language->is_default,
                'image' => $language->image,
            ];

            // only default_lang should have translations, so list = empty
            if ($systemType === 'app') {
                $lang['translations_app'] = [];
            } elseif ($systemType === 'web') {
                $lang['translations_web'] = [];
            } else {
                $lang['translations_app'] = [];
                $lang['translations_web'] = [];
            }

            return $lang;
        });

        // 🔹 Default language with translations
        $defaultLangData = [
            'id' => $defaultLang->id,
            'name' => $defaultLang->name,
            'code' => $defaultLang->code,
            'is_rtl' => (bool) $defaultLang->rtl,
            'is_default' => (bool) $defaultLang->is_default,
            'image' => $defaultLang->image,
        ];

        if ($systemType === 'app') {
            $file_app = resource_path("lang/{$defaultLang->code}_app.json");
            $defaultLangData['translations_app'] = File::exists($file_app)
                ? json_decode(File::get($file_app), true)
                : [];
        } elseif ($systemType === 'web') {
            $file_web = resource_path("lang/{$defaultLang->code}_web.json");
            $defaultLangData['translations_web'] = File::exists($file_web)
                ? json_decode(File::get($file_web), true)
                : [];
        } else {
            $file_app = resource_path("lang/{$defaultLang->code}_app.json");
            $file_web = resource_path("lang/{$defaultLang->code}_web.json");

            $defaultLangData['translations_app'] = File::exists($file_app)
                ? json_decode(File::get($file_app), true)
                : [];

            $defaultLangData['translations_web'] = File::exists($file_web)
                ? json_decode(File::get($file_web), true)
                : [];
        }

        $result = [
            'languages' => $formattedLanguages,
            'default_lang' => $defaultLangData,
        ];

        return ApiResponseService::successResponse('Language Fetched Successfully', $result);
    }

    /**
     * Get Sales Chart Data
     */
    public function getSalesChartData(Request $request)
    {
        try {
            // For now, returning static data as per your requirement
            // In a real application, you would query the database for actual sales data
            $salesChartData = [
                [
                    'name' => 'Jan',
                    'sales' => 3,
                    'revenue' => 1000,
                    'profit' => 10000,
                ],
                [
                    'name' => 'Feb',
                    'sales' => 4,
                    'revenue' => 1800,
                    'profit' => 6000,
                ],
                [
                    'name' => 'Mar',
                    'sales' => 2,
                    'revenue' => 2000,
                    'profit' => 7000,
                ],
                [
                    'name' => 'Apr',
                    'sales' => 2,
                    'revenue' => 3000,
                    'profit' => 14000,
                ],
                [
                    'name' => 'May',
                    'sales' => 12,
                    'revenue' => 18750,
                    'profit' => 17000,
                ],
                [
                    'name' => 'Jun',
                    'sales' => 5,
                    'revenue' => 4000,
                    'profit' => 10000,
                ],
                [
                    'name' => 'Jul',
                    'sales' => 10,
                    'revenue' => 3000,
                    'profit' => 3500,
                ],
                [
                    'name' => 'Aug',
                    'sales' => 9,
                    'revenue' => 4500,
                    'profit' => 8500,
                ],
                [
                    'name' => 'Sep',
                    'sales' => 4,
                    'revenue' => 4800,
                    'profit' => 700,
                ],
                [
                    'name' => 'Oct',
                    'sales' => 4,
                    'revenue' => 5000,
                    'profit' => 12500,
                ],
                [
                    'name' => 'Nov',
                    'sales' => 3,
                    'revenue' => 6000,
                    'profit' => 5500,
                ],
                [
                    'name' => 'Dec',
                    'sales' => 3,
                    'revenue' => 8500,
                    'profit' => 7000,
                ],
            ];

            return ApiResponseService::successResponse('Sales chart data retrieved successfully', $salesChartData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponseService::errorResponse('Failed to retrieve sales chart data: ' . $e->getMessage());
        }
    }

    /**
     * Get FAQs (Frequently Asked Questions)
     */
    public function getFaqs(Request $request)
    {
        try {
            // Get pagination parameters
            $perPage = $request->input('per_page', 15);
            $page = $request->input('page', 1);

            // Validate pagination parameters
            $perPage = max(1, min(100, (int) $perPage)); // Limit between 1 and 100
            $page = max(1, (int) $page);

            // Get only active FAQs with pagination
            $faqs = Faq::where('is_active', true)->orderBy('sequence')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);

            // Transform data for response
            $faqs->getCollection()->transform(static fn($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'created_at' => $faq->created_at,
                'updated_at' => $faq->updated_at,
            ]);

            return ApiResponseService::successResponse('FAQs retrieved successfully', $faqs);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getFaqs Method');
            return ApiResponseService::errorResponse('Failed to retrieve FAQs');
        }
    }

    public function getPages(Request $request)
    {
        try {
            $type = $request->input('type'); // page_type filter
            $languageId = $request->input('language_id'); // language_id filter
            $languageCode = $request->input('language_code'); // language_code filter

            $pagesQuery = Page::where('status', 1)->with('language'); // Only active pages

            // Filter by page_type if provided
            if (!empty($type)) {
                $pagesQuery->where('page_type', $type);
            }

            // Filter by language_code if provided (priority over language_id)
            if (!empty($languageCode)) {
                $language = Language::where('code', $languageCode)->where('status', 1)->first();
                if ($language) {
                    $pagesQuery->where('language_id', $language->id);
                } else {
                    // If language code not found, return empty result
                    return ApiResponseService::successResponse('Pages retrieved successfully', []);
                }
            }
            // Filter by language_id if provided (only if language_code is not provided)
            elseif (!empty($languageId)) {
                $pagesQuery->where('language_id', $languageId);
            }

            $pages = $pagesQuery
                ->orderBy('id', 'asc')
                ->get()
                ->map(static function ($page) {
                    // Map page_type to slug
                    $pageTypeSlugMap = [
                        'About Us' => 'about-us',
                        'Cookies Policy' => 'cookies-policy',
                        'Privacy Policy' => 'privacy-policy',
                        'Terms & Conditions' => 'terms-and-conditions',
                        'Custom' => 'custom',
                    ];

                    $pageTypeSlug = $pageTypeSlugMap[$page->page_type] ?? strtolower(str_replace(
                        ' ',
                        '-',
                        $page->page_type,
                    ));

                    return [
                        'id' => $page->id,
                        'language_id' => $page->language_id,
                        'language_name' => $page->language->name ?? null,
                        'title' => $page->title,
                        'page_type' => $page->page_type,
                        'page_type_slug' => $pageTypeSlug,
                        'slug' => $page->slug,
                        'page_content' => $page->page_content,
                        'page_icon' => $page->page_icon,
                        'og_image' => $page->og_image,
                        'schema_markup' => $page->schema_markup,
                        'meta_title' => $page->meta_title,
                        'meta_description' => $page->meta_description,
                        'meta_keywords' => $page->meta_keywords,
                        'is_custom' => $page->is_custom,
                        'is_termspolicy' => $page->is_termspolicy,
                        'is_privacypolicy' => $page->is_privacypolicy,
                        'status' => $page->status,
                        'created_at' => $page->created_at,
                        'updated_at' => $page->updated_at,
                    ];
                });

            return ApiResponseService::successResponse('Pages retrieved successfully', $pages);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getPages Method');
            return ApiResponseService::errorResponse('Failed to retrieve pages');
        }
    }

    /**
     * Get SEO Settings
     * Accepts language_id or language_code (language_code takes priority) and type (page_type)
     */
    public function getSeoSettings(Request $request)
    {
        try {
            $type = $request->input('type'); // page_type filter
            $languageId = $request->input('language_id'); // language_id filter
            $languageCode = $request->input('language_code'); // language_code filter

            $seoSettingsQuery = SeoSetting::with('language');

            // Filter by page_type if provided
            if (!empty($type)) {
                $seoSettingsQuery->where('page_type', $type);
            }

            // Filter by language_code if provided (priority over language_id)
            if (!empty($languageCode)) {
                $language = Language::where('code', $languageCode)->where('status', 1)->first();
                if ($language) {
                    $seoSettingsQuery->where('language_id', $language->id);
                } else {
                    // If language code not found, return empty result
                    return ApiResponseService::successResponse('SEO settings retrieved successfully', []);
                }
            }
            // Filter by language_id if provided (only if language_code is not provided)
            elseif (!empty($languageId)) {
                $seoSettingsQuery->where('language_id', $languageId);
            }

            $seoSettings = $seoSettingsQuery
                ->orderBy('id', 'asc')
                ->get()
                ->map(static fn($seoSetting) => [
                    'id' => $seoSetting->id,
                    'language_id' => $seoSetting->language_id,
                    'language_name' => $seoSetting->language->name ?? null,
                    'language_code' => $seoSetting->language->code ?? null,
                    'page_type' => $seoSetting->page_type,
                    'meta_title' => $seoSetting->meta_title,
                    'meta_description' => $seoSetting->meta_description,
                    'meta_keywords' => $seoSetting->meta_keywords,
                    'schema_markup' => $seoSetting->schema_markup,
                    'og_image' => $seoSetting->og_image
                        ? url(\Illuminate\Support\Facades\Storage::url($seoSetting->og_image))
                        : null,
                    'created_at' => $seoSetting->created_at,
                    'updated_at' => $seoSetting->updated_at,
                ]);

            return ApiResponseService::successResponse('SEO settings retrieved successfully', $seoSettings);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getSeoSettings Method');
            return ApiResponseService::errorResponse('Failed to retrieve SEO settings');
        }
    }

    /**
     * Check if logged-in user's email exists
     */
    public function isEmailExist(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return ApiResponseService::unauthorizedResponse('User not authenticated');
            }

            $emailExists = !empty($user->email);

            return ApiResponseService::successResponse('Email check completed', [
                'email_exists' => $emailExists,
                'email' => $user->email ?? null,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> isEmailExist Method');
            return ApiResponseService::errorResponse('Failed to check email existence');
        }
    }
    /**
     * Submit Become an Instructor form
     */
    public function submitBecomeInstructor(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:20',
                'specialty' => 'nullable|string|max:255',
                'experience_bio' => 'nullable|string|max:2000',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Save to database
            $instructorRequest = \App\Models\InstructorRequest::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'specialty' => $request->specialty,
                'experience_bio' => $request->experience_bio,
                'status' => 'pending',
            ]);

            // Log the request
            Log::info('New Become an Instructor request submitted:', [
                'id' => $instructorRequest->id,
                'name' => $instructorRequest->name,
                'email' => $instructorRequest->email,
            ]);

            return ApiResponseService::successResponse(
                'Your request has been submitted successfully! We will contact you soon.',
                ['request_id' => $instructorRequest->id]
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            ApiResponseService::logErrorResponse($th, 'API Controller -> submitBecomeInstructor Method');
            return ApiResponseService::errorResponse('Failed to submit request. Please try again later.', exception: $th);
        }
    }

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
            $sessions = $user->tokens->map(function ($token) use ($user) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'ip_address' => $token->ip_address,
                    'user_agent' => $token->user_agent,
                    'last_used_at' => $token->last_used_at,
                    'is_current' => $token->id === $user->currentAccessToken()->id,
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
                return ApiResponseService::errorResponse('Session not found');
            }

            $token->delete();
            return ApiResponseService::successResponse('Session logged out successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    public function userLogout(Request $request)
    {
        try {
            $user = Auth::user();
            if ($user) {
                // Revoke all tokens for this user
                $user->tokens()->delete();
            }

            return ApiResponseService::successResponse('Logged out from all sessions successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return ApiResponseService::errorResponse($th->getMessage());
        }
    }

    public function getNotificationSettings(Request $request)
    {
        try {
            $user = Auth::user();
            $settings = \App\Models\NotificationSetting::where('user_id', $user->id)->get();
            
            // Default settings if empty
            if ($settings->isEmpty()) {
                $keys = ['course_updates', 'marketing', 'wallet_activity', 'new_messages'];
                foreach ($keys as $key) {
                    \App\Models\NotificationSetting::create([
                        'user_id' => $user->id,
                        'setting_key' => $key,
                        'email_enabled' => true,
                        'push_enabled' => true,
                    ]);
                }
                $settings = \App\Models\NotificationSetting::where('user_id', $user->id)->get();
            }

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
                'settings.*.setting_key' => 'required|string',
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
            return ApiResponseService::errorResponse('Unauthenticated', 401);
        }

        $perPage = (int) $request->input('per_page', 15);
        
        $notifications = \App\Models\UserNotification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
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
            return ApiResponseService::errorResponse('Unauthenticated', 401);
        }

        $count = \App\Models\UserNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $count
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
            return ApiResponseService::errorResponse('Unauthenticated', 401);
        }

        $notification = \App\Models\UserNotification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($notification) {
            $notification->update(['is_read' => true]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllMyNotificationsRead(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthenticated', 401);
        }

        \App\Models\UserNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }
}
