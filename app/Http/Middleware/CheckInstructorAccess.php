<?php

namespace App\Http\Middleware;

use App\Models\Instructor;
use App\Services\ApiResponseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckInstructorAccess
{
    /**
     * Handle an incoming request.
     *
     * Checks if user is active and instructor is not suspended
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user) {
            return ApiResponseService::errorResponse('Unauthenticated', null, 401);
        }

        // Check if user is active
        if ($user->is_active != 1) {
            return ApiResponseService::errorResponse(
                'Your account has been deactivated. Please contact support.',
                null,
                403,
            );
        }

        // Check if user is an instructor
        $instructor = Instructor::where('user_id', $user->id)->first();

        if ($instructor) {
            // Check if instructor is suspended
            if ($instructor->status === 'suspended') {
                return ApiResponseService::errorResponse(
                    'Your instructor account has been suspended. Please contact support.',
                    null,
                    403,
                );
            }
        }

        return $next($request);
    }
}
