<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ForceJsonResponseToSnakeCase
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Health probes must stay cheap. Recursing a large JSON body can OOM FPM (256M).
        if ($request->is(
            'api/health',
            'api/health/live',
            'api/health/ready',
            'api/admin/reports*',
            'api/reports*',
            'api/admin/analytics*',
        )) {
            return $next($request);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = json_decode($response->content(), true);

            if (is_array($data)) {
                $data = $this->arrayKeysToSnakeCase($data);
                $response->setData($data);
            }
        }

        return $response;
    }

    /**
     * Recursively convert array keys to snake_case.
     *
     * @param array $array
     * @return array
     */
    private function arrayKeysToSnakeCase(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $key = Str::snake($key);
            }

            if (is_array($value)) {
                $value = $this->arrayKeysToSnakeCase($value);
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
