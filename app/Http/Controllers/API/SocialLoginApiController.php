<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\ApiController;
use App\Models\SocialLogin;
use App\Models\User;
use App\Models\UserFcmToken;
use App\Models\UserSocialAccount;
use App\Services\ApiResponseService;
use App\Services\ApiService;
use App\Services\HelperService;
use App\Support\RoleManager;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialLoginApiController extends ApiController
{
    private const SUPPORTED_PROVIDERS = ['google', 'apple'];

    /**
     * Handle social login/callback (e.g., POST /api/social-login/google)
     *
     * Supports TWO authentication flows:
     *
     * ── Flow A: Firebase ID Token (recommended for SPA / Mobile) ──────────
     *   Frontend: Firebase SDK performs Google Sign-In → returns firebase_token (ID Token)
     *   Request body: { firebase_token: "eyJ..." }
     *   Backend: Verifies the token with Firebase Admin SDK, extracts email & UID,
     *            then finds/creates the user in the database.
     *
     * ── Flow B: Socialite Access Token (legacy / web OAuth) ──────────────
     *   Frontend: Google OAuth flow → returns an access_token
     *   Request body: { access_token: "ya29..." }  (or provider_token / token)
     *   Backend: Calls Socialite::driver('google')->userFromToken($token)
     *
     * If BOTH are present, Flow A (Firebase) takes priority.
     */
    public function handleSocialLogin(Request $request, string $provider)
    {
        $provider = strtolower(trim($provider));

        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return ApiResponseService::validationError('مزود تسجيل الدخول الاجتماعي غير مدعوم.');
        }

        $firebaseToken = trim((string) $request->input('firebase_token', ''));
        $rawAccessToken = trim((string) (
            $request->input('access_token')
            ?? $request->input('provider_token')
            ?? $request->input('token')
            ?? ''
        ));

        // Google OAuth access tokens (ya29…) are distinct from Firebase JWT ID tokens.
        $googleAccessToken = $this->isGoogleAccessToken($rawAccessToken) ? $rawAccessToken : '';

        if ($firebaseToken === '' && $rawAccessToken === '') {
            return ApiResponseService::validationError('مطلوب رمز Firebase أو رمز دخول Google.');
        }

        try {
            // Flow A: Firebase ID token. If Admin SDK is missing/misconfigured,
            // fall back to Socialite using the Google access token — never treat a JWT as an access token.
            if ($firebaseToken !== '') {
                $firebaseReady = $this->firebaseIdTokenLooksVerifiable($firebaseToken);
                if ($firebaseReady) {
                    return $this->handleFirebaseTokenLogin($request, $provider, $firebaseToken);
                }
                if ($googleAccessToken !== '') {
                    return $this->handleSocialiteTokenLogin($request, $provider, $googleAccessToken);
                }
                return ApiResponseService::validationError(
                    'تعذر التحقق من حساب Google. تأكد من إعدادات Firebase أو أعد المحاولة.',
                );
            }

            $socialiteToken = $googleAccessToken !== '' ? $googleAccessToken : $rawAccessToken;
            return $this->handleSocialiteTokenLogin($request, $provider, $socialiteToken);

        } catch (ClientException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 401;
            return response()->json([
                'status'  => false,
                'success' => false,
                'error'   => true,
                'message' => 'رمز Google غير صالح أو منتهٍ. يرجى تسجيل الدخول مرة أخرى.',
            ], $statusCode >= 400 && $statusCode < 500 ? 401 : 500);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }

    private function isGoogleAccessToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        if (substr_count($token, '.') === 2) {
            return false;
        }

        return str_starts_with($token, 'ya29');
    }

    /**
     * Probe Firebase Admin without consuming the login response.
     * Missing credentials or a non-Firebase JWT returns false so Socialite can run.
     */
    private function firebaseIdTokenLooksVerifiable(#[\SensitiveParameter] string $token): bool
    {
        try {
            $verified = \App\Services\HelperService::verifyToken($token);

            return !empty($verified);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 500;

            return $status >= 200 && $status < 300;
        } catch (Throwable) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Flow A: Firebase ID Token (kreait/firebase-php)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Authenticate the user using a Firebase ID Token.
     *
     * 1. Verifies the token with Firebase Admin SDK (kreait/firebase-php via ApiService).
     * 2. Extracts the UID (sub) and email from the decoded token.
     * 3. Finds or creates the user in the local database.
     * 4. Links a SocialLogin record (type = provider) to the user.
     * 5. Issues a Sanctum API token.
     */
    private function handleFirebaseTokenLogin(Request $request, string $provider, string $firebaseToken)
    {
        // Verify the Firebase ID token and extract claims
        $verifiedToken = ApiService::verifyFirebaseToken($firebaseToken);
        $claims        = $verifiedToken->claims();

        $firebaseUid   = $claims->get('sub');
        $rawEmail      = $claims->get('email');
        $email         = $rawEmail ? strtolower(trim((string) $rawEmail)) : null;
        $name          = $claims->get('name') ?? ($email ? explode('@', $email)[0] : 'User');
        $avatar        = $claims->get('picture') ?? null;

        // email is the primary identifier; bail early if Firebase didn't include it
        if (empty($email)) {
            return ApiResponseService::validationError('تعذر الحصول على البريد الإلكتروني من حساب Google.');
        }

        $user = DB::transaction(function () use ($firebaseUid, $email, $name, $avatar, $provider) {
            // ── 1. Check existing SocialLogin record (Firebase UID) ───────
            $socialLogin = SocialLogin::where('firebase_id', $firebaseUid)
                ->where('type', $provider)
                ->with('user')
                ->first();

            if ($socialLogin && $socialLogin->user) {
                if ($socialLogin->user->trashed() || (isset($socialLogin->user->is_active) && !$socialLogin->user->is_active)) {
                    ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
                }
                return $socialLogin->user;
            }

            // ── 2. Find existing user by email ────────────────────────────
            $user = User::withTrashed()->where('email', $email)->first();

            if ($user) {
                if ($user->trashed() || (isset($user->is_active) && !$user->is_active)) {
                    ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
                }
            } else {
                // ── 3. Create new user ────────────────────────────────────
                $slug = HelperService::generateUniqueSlug(User::class, $name);
                $user = User::create([
                    'name'      => $name,
                    'email'     => $email,
                    'password'  => Hash::make(Str::random(24)),
                    'profile'   => $avatar,
                    'slug'      => $slug,
                    'is_active' => 1,
                    'type'      => $provider,
                ]);
                $user->email_verified_at = now();
                $user->save();

                RoleManager::assignStudentRole($user);
                $user->notify(new \App\Notifications\WelcomeNotification($user));
            }

            // ── 4. Conflict check & Link Firebase UID to local user ────────
            $otherSocialLogin = SocialLogin::where('firebase_id', $firebaseUid)
                ->where('type', $provider)
                ->first();

            if ($otherSocialLogin && $otherSocialLogin->user_id !== $user->id) {
                ApiResponseService::validationError('حساب Google هذا مرتبط بمستخدم آخر.');
            }

            SocialLogin::updateOrCreate(
                ['user_id' => $user->id, 'type' => $provider],
                ['firebase_id' => $firebaseUid],
            );

            return $user;
        });

        // ── Guard checks ─────────────────────────────────────────────────
        if ($user->trashed() || (isset($user->is_active) && !$user->is_active)) {
            return ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
        }

        if (!$user->hasAnyRole(RoleManager::getCandidateRoleNames('user'))) {
            return ApiResponseService::validationError('بيانات الدخول غير صحيحة.');
        }

        $fcmId = $request->fcm_id ?? $request->fcm_token;
        if (!empty($fcmId)) {
            UserFcmToken::updateOrCreate(['fcm_token' => $fcmId], [
                'user_id'       => $user->id,
                'platform_type' => $request->platform_type,
                'created_at'    => Carbon::now(),
                'updated_at'    => Carbon::now(),
            ]);
        }

        $deviceError = \App\Services\AuthDeviceService::verifyDeviceLimits($user, $request);
        if ($deviceError) {
            return ApiResponseService::errorResponse($deviceError['message'], ['error_code' => $deviceError['code'] ?? 'DEVICE_ERROR'], 403);
        }

        // ── Issue Sanctum token ──────────────────────────────────────────
        $pair = $this->createTokenPair($user, $request->device_id ?? $user->name ?? $provider, $request);
        $formattedUser = $this->formatUserWithRolesAndPermissions($user, $pair['access'], $pair['refresh']);

        ApiResponseService::successResponse('User logged-in successfully', $formattedUser);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Flow B: Socialite Access Token (legacy)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Authenticate the user using a raw OAuth Access Token via Socialite.
     * Throws GuzzleHttp\Exception\ClientException if the token is expired/invalid
     * — caught by the parent handleSocialLogin() and converted to a clean 401.
     */
    private function handleSocialiteTokenLogin(Request $request, string $provider, string $accessToken)
    {
        /** @var \Laravel\Socialite\Two\User $socialUser */
        $socialUser = Socialite::driver($provider)->stateless()->userFromToken($accessToken);

        $rawEmail = $socialUser->getEmail();
        $email = $rawEmail ? strtolower(trim((string) $rawEmail)) : null;

        if (empty($email)) {
            return ApiResponseService::validationError('تعذر الحصول على البريد الإلكتروني من حساب Google.');
        }

        $user = DB::transaction(function () use ($socialUser, $provider, $email) {
            // Try to find via linked social account first
            $socialAccount = UserSocialAccount::where('provider', $provider)
                ->where('provider_id', $socialUser->getId())
                ->with('user')
                ->first();

            if ($socialAccount && $socialAccount->user) {
                if ($socialAccount->user->trashed() || (isset($socialAccount->user->is_active) && !$socialAccount->user->is_active)) {
                    ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
                }
                $socialAccount->update(['token' => null]);
                return $socialAccount->user;
            }

            // Try to find existing user by email
            $user = User::withTrashed()->where('email', $email)->first();

            if ($user) {
                if ($user->trashed() || (isset($user->is_active) && !$user->is_active)) {
                    ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
                }
            } else {
                $name   = $socialUser->getName() ?? $socialUser->getNickname() ?? explode('@', $email)[0];
                $slug   = HelperService::generateUniqueSlug(User::class, $name);
                $avatar = $socialUser->getAvatar();

                $user = User::create([
                    'name'      => $name,
                    'email'     => $email,
                    'password'  => Hash::make(Str::random(24)),
                    'profile'   => $avatar,
                    'slug'      => $slug,
                    'is_active' => 1,
                    'type'      => $provider,
                ]);
                $user->email_verified_at = now();
                $user->save();

                RoleManager::assignStudentRole($user);
                $user->notify(new \App\Notifications\WelcomeNotification($user));
            }

            $otherAccount = UserSocialAccount::where('provider', $provider)
                ->where('provider_id', $socialUser->getId())
                ->first();

            if ($otherAccount && $otherAccount->user_id !== $user->id) {
                ApiResponseService::validationError('حساب Google هذا مرتبط بمستخدم آخر.');
            }

            UserSocialAccount::updateOrCreate(
                ['provider' => $provider, 'provider_id' => $socialUser->getId()],
                ['user_id' => $user->id, 'token' => null],
            );

            return $user;
        });

        // ── Guard checks ─────────────────────────────────────────────────
        if ($user->trashed() || (isset($user->is_active) && !$user->is_active)) {
            return ApiResponseService::validationError('تم تعطيل الحساب. يرجى التواصل مع الدعم.');
        }

        if (!$user->hasAnyRole(RoleManager::getCandidateRoleNames('user'))) {
            return ApiResponseService::validationError('بيانات الدخول غير صحيحة.');
        }

        $fcmId = $request->fcm_id ?? $request->fcm_token;
        if (!empty($fcmId)) {
            UserFcmToken::updateOrCreate(['fcm_token' => $fcmId], [
                'user_id'       => $user->id,
                'platform_type' => $request->platform_type,
                'created_at'    => Carbon::now(),
                'updated_at'    => Carbon::now(),
            ]);
        }

        $deviceError = \App\Services\AuthDeviceService::verifyDeviceLimits($user, $request);
        if ($deviceError) {
            return ApiResponseService::errorResponse($deviceError['message'], ['error_code' => $deviceError['code'] ?? 'DEVICE_ERROR'], 403);
        }

        // ── Issue Sanctum token ──────────────────────────────────────────
        $pair = $this->createTokenPair($user, $request->device_id ?? $user->name ?? $provider, $request);
        $formattedUser = $this->formatUserWithRolesAndPermissions($user, $pair['access'], $pair['refresh']);

        ApiResponseService::successResponse('User logged-in successfully', $formattedUser);
    }

}
