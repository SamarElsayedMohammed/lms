<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes an admin audit row for successful mutating admin API calls.
 * Also catches HttpResponseException because many controllers finish via
 * ApiResponseService::successResponse() which throws instead of returning.
 */
class LogAdminMutationMiddleware
{
    private const SKIP_PATH_FRAGMENTS = [
        'audit-logs',
        'notifications/email-preview',
        'settings/notifications/preview',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $this->writeLog($request, $response);
            throw $e;
        }

        $this->writeLog($request, $response);

        return $response;
    }

    private function writeLog(Request $request, Response $response): void
    {
        if (!$this->shouldLog($request, $response)) {
            return;
        }

        try {
            $path = ltrim($request->path(), '/');
            AuditLogService::log(
                action: 'admin_'.$request->method().'_'.str_replace(['/', '.'], '_', $path),
                summary: $request->method().' /'.$path,
                details: [
                    'method' => $request->method(),
                    'path' => '/'.$path,
                    'status' => $response->getStatusCode(),
                    'keys' => array_values(array_diff(array_keys($request->except(['password', 'password_confirmation', 'token', '_token'])), [])),
                ],
            );
        } catch (\Throwable) {
            // Audit must never break the admin action.
        }
    }

    private function shouldLog(Request $request, Response $response): bool
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        if ($response->getStatusCode() >= 400) {
            return false;
        }

        $path = $request->path();
        foreach (self::SKIP_PATH_FRAGMENTS as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        return true;
    }
}
