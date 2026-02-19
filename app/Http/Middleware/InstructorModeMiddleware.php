<?php

namespace App\Http\Middleware;

use App\Services\InstructorModeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InstructorModeMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Share instructor mode with all views
        view()->share('instructorMode', InstructorModeService::getInstructorMode());
        view()->share('isSingleInstructorMode', InstructorModeService::isSingleInstructorMode());
        view()->share('shouldShowInstructorLists', InstructorModeService::shouldShowInstructorLists());
        view()->share('shouldShowInstructorFilters', InstructorModeService::shouldShowInstructorFilters());
        view()->share('shouldShowInstructorManagement', InstructorModeService::shouldShowInstructorManagement());

        return $next($request);
    }
}
