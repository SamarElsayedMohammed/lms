<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoModeMiddleware
{
    /**
     * Handle an incoming request.
     * Block all operations when DEMO_MODE is enabled (1)
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if DEMO_MODE is enabled (1 = on, 0 = off)
        $demoMode = env('DEMO_MODE', 0);

        // Allow Super Admin to bypass demo mode restrictions
        if ($demoMode == 1) {
            // Check web guard (for admin panel)
            if (auth('web')->check()) {
                $user = auth('web')->user();
                if ($user && ($user->hasRole('Super Admin') || $user->hasRole('SuperAdmin'))) {
                    return $next($request);
                }
            }
            // Check sanctum guard (for API)
            if (auth('sanctum')->check()) {
                $user = auth('sanctum')->user();
                if ($user && ($user->hasRole('Super Admin') || $user->hasRole('SuperAdmin'))) {
                    return $next($request);
                }
            }
        }

        // Allow login routes even in demo mode (for both admin and users)
        $allowedRoutes = [
            'login', // Web admin login (GET and POST)
            'api/user-login', // API user login
            'api/mobile-login', // API mobile login
            'api/user-signup', // API user signup
            'api/mobile-registration', // API mobile registration
            'api/user-exists', // API user exists check
            'api/mobile-reset-password', // API password reset
            'api/download-invoice', // API download invoice
            'api/place_order', // API place order
        ];

        $currentRoute = $request->path();
        $isAllowedRoute = false;

        // Check if current route matches any allowed route
        foreach ($allowedRoutes as $route) {
            if (!($currentRoute === $route || $request->is($route))) {
                continue;
            }

            $isAllowedRoute = true;
            break;
        }

        // Also check route name for login routes (more reliable)
        $routeName = $request->route()?->getName();
        if (!$isAllowedRoute && in_array($routeName, ['login', 'login-page'])) {
            $isAllowedRoute = true;
        }

        // Allow cart operations (add, remove, clear, apply-promo, remove-promo)
        if (!$isAllowedRoute && $request->is('api/cart/*')) {
            $isAllowedRoute = true;
        }

        // Allow wishlist operations (add-update-wishlist)
        if (!$isAllowedRoute && $request->is('api/wishlist/*')) {
            $isAllowedRoute = true;
        }

        // Allow refund operations (request, my-refunds, check-eligibility)
        if (!$isAllowedRoute && $request->is('api/refund/*')) {
            $isAllowedRoute = true;
        }

        // Allow curriculum operations (mark-completed, progress tracking, etc.)
        if (!$isAllowedRoute && $request->is('api/curriculum/*')) {
            $isAllowedRoute = true;
        }

        // Allow track operations (course tracking, chapter tracking)
        if (!$isAllowedRoute && $request->is('api/track/*')) {
            $isAllowedRoute = true;
        }

        // If demo mode is on, block only write operations (POST, PUT, PATCH, DELETE)
        // Allow GET, HEAD, OPTIONS for viewing data
        // Allow login routes for both admin and users
        if ($demoMode == 1 && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE']) && !$isAllowedRoute) {
            // Check if request is API or AJAX (expects JSON response)
            if ($request->expectsJson() || $request->ajax() || $request->is('api/*')) {
                // Return success response with info message
                return response()->json([
                    'error' => false,
                    'message' => 'Not allow any operation in demo mode.',
                    'data' => (object) [],
                    'code' => 200,
                ], 200);
            } else {
                // For web routes, redirect back with success message
                return redirect()->back()->with('success', 'Not allow any operation in demo mode.')->send();
            }
        }

        return $next($request);
    }
}
