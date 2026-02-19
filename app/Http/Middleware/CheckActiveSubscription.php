<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to check if user has an active subscription
 * No grace period — access ends immediately on expiry
 */
final class CheckActiveSubscription
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => true,
                'message' => 'يجب تسجيل الدخول للوصول إلى هذا المحتوى.',
                'code' => 'AUTHENTICATION_REQUIRED',
            ], 401);
        }

        $hasAccess = $this->subscriptionService->checkAccess($user);

        if (!$hasAccess) {
            return response()->json([
                'error' => true,
                'message' => 'يجب الاشتراك للوصول إلى هذا المحتوى.',
                'code' => 'SUBSCRIPTION_REQUIRED',
                'subscription_status' => 'no_subscription',
                'redirect_to' => '/subscription/plans',
            ], 403);
        }

        return $next($request);
    }
}
