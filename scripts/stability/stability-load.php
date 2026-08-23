<?php

declare(strict_types=1);

/**
 * Rate-limited HTTP workload generator for a staging Skillso environment.
 * It deliberately refuses known production hosts unless --allow-production is set.
 */

$options = getopt('', [
    'base-url:',
    'duration::',
    'concurrency::',
    'rate::',
    'phase::',
    'evidence-dir::',
    'endpoints::',
    'token-env::',
    'allow-production',
]);

$baseUrl = rtrim((string) ($options['base-url'] ?? ''), '/');
if ($baseUrl === '') {
    fwrite(STDERR, "--base-url is required.\n");
    exit(64);
}

$host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
$knownProduction = $host === 'api.skillso.net'
    || $host === 'skillso.net'
    || $host === '187.77.77.216'
    || str_contains($host, '187.77.77.216.sslip.io');
if ($knownProduction && ! array_key_exists('allow-production', $options)) {
    fwrite(STDERR, "Refusing a known production target without --allow-production. Use staging.\n");
    exit(65);
}

$duration = max(1, min(259200, (int) ($options['duration'] ?? 60)));
$concurrency = max(1, min(64, (int) ($options['concurrency'] ?? 4)));
$rate = max(0.1, min(100.0, (float) ($options['rate'] ?? 5)));
$phase = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($options['phase'] ?? 'active-load'));
$evidenceDir = (string) ($options['evidence-dir'] ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'skillso-stability');
$endpointList = (string) ($options['endpoints'] ?? '/api/health/live,/api/health/ready,/api/health,/api/get-courses?per_page=10');
$endpoints = array_values(array_filter(array_map('trim', explode(',', $endpointList))));
if ($endpoints === []) {
    fwrite(STDERR, "At least one endpoint is required.\n");
    exit(64);
}

if (! is_dir($evidenceDir) && ! mkdir($evidenceDir, 0o775, true) && ! is_dir($evidenceDir)) {
    throw new RuntimeException("Unable to create evidence directory: {$evidenceDir}");
}

$tokenEnv = (string) ($options['token-env'] ?? 'STABILITY_BEARER_TOKEN');
$token = trim((string) getenv($tokenEnv));
$runId = gmdate('Ymd\THis\Z').'-'.$phase;
$csvPath = $evidenceDir.DIRECTORY_SEPARATOR.$runId.'-requests.csv';
$summaryPath = $evidenceDir.DIRECTORY_SEPARATOR.$runId.'-summary.json';
$csv = fopen($csvPath, 'wb');
if ($csv === false) {
    throw new RuntimeException("Unable to create {$csvPath}");
}
fputcsv($csv, ['timestamp_utc', 'phase', 'endpoint', 'status', 'latency_ms', 'bytes', 'error'], ',', '"', '');

$multi = curl_multi_init();
$active = [];
$latencies = [];
$statuses = [];
$errors = 0;
$requests = 0;
$endpointIndex = 0;
$startedAt = microtime(true);
$deadline = $startedAt + $duration;
$nextDispatch = $startedAt;
$dispatchInterval = 1 / $rate;

$createHandle = static function (string $url, string $endpoint, string $token): CurlHandle {
    $headers = ['Accept: application/json', 'User-Agent: Skillso-Stability-Certification/1.0'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer '.$token;
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_PRIVATE => $endpoint,
    ]);

    return $handle;
};

do {
    $now = microtime(true);
    while ($now < $deadline && count($active) < $concurrency && $now >= $nextDispatch) {
        $endpoint = $endpoints[$endpointIndex++ % count($endpoints)];
        $url = $baseUrl.'/'.ltrim($endpoint, '/');
        $handle = $createHandle($url, $endpoint, $token);
        $key = spl_object_id($handle);
        $active[$key] = ['handle' => $handle, 'started' => microtime(true)];
        curl_multi_add_handle($multi, $handle);
        $nextDispatch += $dispatchInterval;
        $now = microtime(true);
    }

    do {
        $multiStatus = curl_multi_exec($multi, $running);
    } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

    while (($info = curl_multi_info_read($multi)) !== false) {
        /** @var CurlHandle $handle */
        $handle = $info['handle'];
        $key = spl_object_id($handle);
        $metadata = $active[$key];
        $endpoint = (string) curl_getinfo($handle, CURLINFO_PRIVATE);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $latencyMs = round((microtime(true) - $metadata['started']) * 1000, 2);
        $body = curl_multi_getcontent($handle);
        $error = curl_error($handle);
        if ($info['result'] !== CURLE_OK || $error !== '') {
            $errors++;
        }
        $requests++;
        $latencies[] = $latencyMs;
        $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        fputcsv($csv, [gmdate('c'), $phase, $endpoint, $status, $latencyMs, strlen((string) $body), $error], ',', '"', '');
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
        unset($active[$key]);
    }

    if ($running > 0) {
        curl_multi_select($multi, 0.05);
    } elseif (microtime(true) < $deadline) {
        usleep(10_000);
    }
} while (microtime(true) < $deadline || $active !== []);

fclose($csv);
curl_multi_close($multi);
sort($latencies);

$percentile = static function (array $values, float $percentile): float {
    if ($values === []) {
        return 0.0;
    }
    $index = (int) ceil(($percentile / 100) * count($values)) - 1;

    return (float) $values[max(0, min(count($values) - 1, $index))];
};

ksort($statuses);
$summary = [
    'run_id' => $runId,
    'target_host' => $host,
    'phase' => $phase,
    'started_at_utc' => gmdate('c', (int) $startedAt),
    'finished_at_utc' => gmdate('c'),
    'configured_duration_seconds' => $duration,
    'configured_concurrency' => $concurrency,
    'configured_rate_rps' => $rate,
    'requests' => $requests,
    'transport_errors' => $errors,
    'status_counts' => $statuses,
    'latency_ms' => [
        'min' => $latencies === [] ? 0 : min($latencies),
        'p50' => $percentile($latencies, 50),
        'p95' => $percentile($latencies, 95),
        'p99' => $percentile($latencies, 99),
        'max' => $latencies === [] ? 0 : max($latencies),
    ],
    'peak_generator_memory_bytes' => memory_get_peak_usage(true),
    'request_evidence' => basename($csvPath),
];

file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

$has503 = ($statuses[503] ?? 0) > 0;
$hasServerErrors = array_sum(array_filter($statuses, static fn (int $count, int $status): bool => $status >= 500, ARRAY_FILTER_USE_BOTH)) > 0;
exit(($errors > 0 || $has503 || $hasServerErrors) ? 2 : 0);
