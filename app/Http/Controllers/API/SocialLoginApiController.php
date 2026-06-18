<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\ApiController;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\ApiResponseService;
use App\Services\HelperService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialLoginApiController extends ApiController
{
    /**
     * Handle social login/callback (e.g., POST /api/social-login/google)
     *
     * Accepts:
     *   - access_token   (Google OAuth access token) — used with Socialite
     *   - firebase_token (Firebase ID token)         — optional fallback / extra verification
     *   - provider_token (alias for access_token)
     *   - token          (alias for access_token)
     *   - email          (hint, not trusted alone)
     *   - device_type, platform_type                 — forwarded to token metadata
     */
    public function handleSocialLogin(Request $request, string $provider)
    {
        // Normalise: pick whichever token key the client sent
        $accessToken = $request->input('access_token')
            ?? $request->input('provider_token')
            ?? $request->input('token');

        if (empty($accessToken)) {
            return ApiResponseService::validationError('access_token is required.');
        }

        try {
            // ── 1. Resolve Google user via Socialite ─────────────────
            /** @var \Laravel\Socialite\Two\User $socialUser */
            $socialUser = Socialite::driver($provider)->stateless()->userFromToken($accessToken);

            $email = $socialUser->getEmail();

            if (empty($email)) {
                return ApiResponseService::validationError('Email address could not be retrieved from ' . ucfirst($provider) . '.');
            }

            // ── 2. Find or create user ───────────────────────────────
            $user = DB::transaction(function () use ($socialUser, $provider, $email) {
                // Try to find via linked social account first
                $socialAccount = UserSocialAccount::where('provider', $provider)
                    ->where('provider_id', $socialUser->getId())
                    ->with('user')
                    ->first();

                if ($socialAccount && $socialAccount->user) {
                    // Refresh the token stored
                    $socialAccount->update(['token' => $socialUser->token]);
                    return $socialAccount->user;
                }

                // Try to find existing user by email
                $user = User::where('email', $email)->first();

                if (!$user) {
                    // Create new user
                    $name     = $socialUser->getName() ?? $socialUser->getNickname() ?? explode('@', $email)[0];
                    $slug     = HelperService::generateUniqueSlug(User::class, $name);
                    $avatar   = $socialUser->getAvatar();

                    $user = User::create([
                        'name'     => $name,
                        'email'    => $email,
                        'password' => Hash::make(Str::random(24)),
                        'profile'  => $avatar,
                        'slug'     => $slug,
                        'is_active'=> 1,
                        'type'     => $provider,
                    ]);
                    // email_verified_at is not in $fillable, set directly
                    $user->email_verified_at = now();
                    $user->save();

                    $user->assignRole(config('constants.SYSTEM_ROLES.USER'));
                }

                // Link (or re-link) the social account
                UserSocialAccount::updateOrCreate(
                    ['provider' => $provider, 'provider_id' => $socialUser->getId()],
                    ['user_id'  => $user->id, 'token' => $socialUser->token],
                );

                return $user;
            });

            // ── 3. Guard checks ──────────────────────────────────────
            if (!empty($user->deleted_at)) {
                return ApiResponseService::validationError('User is deactivated. Please contact the administrator.');
            }

            if (!$user->hasRole(config('constants.SYSTEM_ROLES.USER'))) {
                return ApiResponseService::validationError('Invalid Login Credentials');
            }

            // ── 4. Create token (same helper used by all login flows) ─
            $plainToken   = $this->createToken($user, $user->name ?? $provider, $request);
            $formattedUser = $this->formatUserWithRolesAndPermissions($user, $plainToken);

            ApiResponseService::successResponse('User logged-in successfully', $formattedUser);

        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }

    // ── Private helpers (duplicate-safe wrappers) ────────────────────

    /**
     * Create a Sanctum token with IP/User-Agent metadata.
     * Mirrors ApiController::createTokenWithMetadata (which is private there).
     */
    private function createToken(User $user, string $name, Request $request): string
    {
        // Cap tokens per user at 10 to prevent unbounded growth
        $count = $user->tokens()->count();
        if ($count >= 10) {
            $user->tokens()->oldest()->take($count - 9)->delete();
        }

        $tokenResult = $user->createToken($name);
        $token       = $tokenResult->accessToken;
        $token->ip_address = $request->ip();
        $token->user_agent = $request->userAgent();
        $token->save();

        return $tokenResult->plainTextToken;
    }
}
