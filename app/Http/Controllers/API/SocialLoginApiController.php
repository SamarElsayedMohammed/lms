<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\ApiController;
use App\Models\SocialLogin;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\ApiResponseService;
use App\Services\ApiService;
use App\Services\HelperService;
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
            return ApiResponseService::validationError('Unsupported social provider.');
        }

        $firebaseToken = $request->input('firebase_token');
        $accessToken   = $request->input('access_token')
            ?? $request->input('provider_token')
            ?? $request->input('token');

        if (empty($firebaseToken) && empty($accessToken)) {
            return ApiResponseService::validationError('Either firebase_token or access_token is required.');
        }

        try {
            // ── Flow A: Firebase ID Token ─────────────────────────────────
            if (!empty($firebaseToken)) {
                return $this->handleFirebaseTokenLogin($request, $provider, $firebaseToken);
            }

            // ── Flow B: Socialite Access Token ───────────────────────────
            return $this->handleSocialiteTokenLogin($request, $provider, $accessToken);

        } catch (ClientException $e) {
            // Guzzle 4xx errors (e.g. 401 Unauthorized from Google userinfo endpoint)
            // means the access_token is expired or invalid — return a clean 401.
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 401;
            return response()->json([
                'status'  => false,
                'message' => 'The provided token is invalid or has expired. Please sign in again.',
            ], $statusCode >= 400 && $statusCode < 500 ? 401 : 500);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
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
        $email         = $claims->get('email');
        $name          = $claims->get('name') ?? ($email ? explode('@', $email)[0] : 'User');
        $avatar        = $claims->get('picture') ?? null;

        // email is the primary identifier; bail early if Firebase didn't include it
        if (empty($email)) {
            return ApiResponseService::validationError('Email address could not be retrieved from the Firebase token.');
        }

        $user = DB::transaction(function () use ($firebaseUid, $email, $name, $avatar, $provider) {
            // ── 1. Check existing SocialLogin record (Firebase UID) ───────
            $socialLogin = SocialLogin::where('firebase_id', $firebaseUid)
                ->where('type', $provider)
                ->with('user')
                ->first();

            if ($socialLogin && $socialLogin->user) {
                return $socialLogin->user;
            }

            // ── 2. Find existing user by email ────────────────────────────
            $user = User::where('email', $email)->first();

            if (!$user) {
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

                $user->assignRole(config('constants.SYSTEM_ROLES.USER'));
                $user->notify(new \App\Notifications\WelcomeNotification($user));
            }

            // ── 4. Link Firebase UID to local user ────────────────────────
            SocialLogin::updateOrCreate(
                ['firebase_id' => $firebaseUid, 'type' => $provider],
                ['user_id' => $user->id],
            );

            return $user;
        });

        // ── Guard checks ─────────────────────────────────────────────────
        if (!empty($user->deleted_at)) {
            return ApiResponseService::validationError('User is deactivated. Please contact the administrator.');
        }

        if (!$user->hasRole(config('constants.SYSTEM_ROLES.USER'))) {
            return ApiResponseService::validationError('Invalid Login Credentials');
        }

        // ── Issue Sanctum token ──────────────────────────────────────────
        $pair = $this->createTokenPair($user, $user->name ?? $provider, $request);
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

        $email = $socialUser->getEmail();

        if (empty($email)) {
            return ApiResponseService::validationError('Email address could not be retrieved from ' . ucfirst($provider) . '.');
        }

        $user = DB::transaction(function () use ($socialUser, $provider, $email) {
            // Try to find via linked social account first
            $socialAccount = UserSocialAccount::where('provider', $provider)
                ->where('provider_id', $socialUser->getId())
                ->with('user')
                ->first();

            if ($socialAccount && $socialAccount->user) {
                $socialAccount->update(['token' => $socialUser->token]);
                return $socialAccount->user;
            }

            // Try to find existing user by email
            $user = User::where('email', $email)->first();

            if (!$user) {
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

                $user->assignRole(config('constants.SYSTEM_ROLES.USER'));
                $user->notify(new \App\Notifications\WelcomeNotification($user));
            }

            UserSocialAccount::updateOrCreate(
                ['provider' => $provider, 'provider_id' => $socialUser->getId()],
                ['user_id' => $user->id, 'token' => $socialUser->token],
            );

            return $user;
        });

        // ── Guard checks ─────────────────────────────────────────────────
        if (!empty($user->deleted_at)) {
            return ApiResponseService::validationError('User is deactivated. Please contact the administrator.');
        }

        if (!$user->hasRole(config('constants.SYSTEM_ROLES.USER'))) {
            return ApiResponseService::validationError('Invalid Login Credentials');
        }

        // ── Issue Sanctum token ──────────────────────────────────────────
        $pair = $this->createTokenPair($user, $user->name ?? $provider, $request);
        $formattedUser = $this->formatUserWithRolesAndPermissions($user, $pair['access'], $pair['refresh']);

        ApiResponseService::successResponse('User logged-in successfully', $formattedUser);
    }

}
