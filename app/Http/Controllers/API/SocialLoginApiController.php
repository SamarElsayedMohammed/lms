<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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

class SocialLoginApiController extends Controller
{
    /**
     * Handle social login/callback
     * This endpoint is called with the provider and the token from frontend
     */
    public function handleSocialLogin(Request $request, $provider)
    {
        $request->validate([
            'access_token' => 'required|string',
        ]);

        try {
            // Get user info from Socialite using the token
            $socialUser = Socialite::driver($provider)->userFromToken($request->access_token);

            if (!$socialUser->getEmail()) {
                return ApiResponseService::errorResponse('Email not provided by social provider.');
            }

            $user = DB::transaction(function () use ($socialUser, $provider) {
                // Find or create social account
                $socialAccount = UserSocialAccount::where('provider', $provider)
                    ->where('provider_id', $socialUser->getId())
                    ->first();

                if ($socialAccount) {
                    return $socialAccount->user;
                }

                // Check if user exists with this email
                $user = User::where('email', $socialUser->getEmail())->first();

                if (!$user) {
                    // Create new user
                    $name = $socialUser->getName() ?? $socialUser->getNickname() ?? 'User';
                    $user = User::create([
                        'name' => $name,
                        'email' => $socialUser->getEmail(),
                        'password' => Hash::make(Str::random(24)),
                        'profile' => $socialUser->getAvatar(),
                        'slug' => HelperService::generateUniqueSlug(User::class, $name),
                    ]);
                    
                    // Assign default role
                    $user->assignRole(config('constants.SYSTEM_ROLES.USER'));
                }

                // Link social account
                UserSocialAccount::create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'token' => $socialUser->token,
                ]);

                return $user;
            });

            $token = $user->createToken('social-login')->plainTextToken;

            return ApiResponseService::successResponse('Logged in successfully', [
                'user' => $user,
                'token' => $token,
            ]);

        } catch (Exception $e) {
            return ApiResponseService::errorResponse('Social login failed: ' . $e->getMessage());
        }
    }
}
