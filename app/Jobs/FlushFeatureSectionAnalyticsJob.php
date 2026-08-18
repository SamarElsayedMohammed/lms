<?php

namespace App\Jobs;

use App\Models\FeatureSectionAnalyticsDaily;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class FlushFeatureSectionAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        try {
            $date = now()->format('Y-m-d');

            // Use SCAN instead of KEYS — KEYS is a blocking operation that freezes Redis
            // on large keyspaces. SCAN iterates incrementally without blocking.
            $viewKeys = $this->scanKeys('feature_section:*:views');
            $clickKeys = $this->scanKeys('feature_section:*:clicks');

            if (empty($viewKeys) && empty($clickKeys)) {
                return;
            }

            $interactions = [];

            foreach ($viewKeys as $key) {
                // Redis keys may return with prefix depending on config. Parse section ID.
                // Pattern: feature_section:{id}:views or {prefix}_feature_section:{id}:views
                if (preg_match('/feature_section:(\d+):views/', $key, $matches)) {
                    $sectionId = $matches[1];
                    $views = (int) Redis::get($key);
                    if ($views > 0) {
                        $interactions[$sectionId]['views'] = $views;
                    }
                }
            }

            foreach ($clickKeys as $key) {
                if (preg_match('/feature_section:(\d+):clicks/', $key, $matches)) {
                    $sectionId = $matches[1];
                    $clicks = (int) Redis::get($key);
                    if ($clicks > 0) {
                        $interactions[$sectionId]['clicks'] = $clicks;
                    }
                }
            }

            // Process and reset Redis counters
            foreach ($interactions as $sectionId => $data) {
                $views = $data['views'] ?? 0;
                $clicks = $data['clicks'] ?? 0;

                // Upsert in the DB
                $analytics = FeatureSectionAnalyticsDaily::firstOrCreate(
                    ['feature_section_id' => $sectionId, 'date' => $date],
                    ['views' => 0, 'clicks' => 0, 'enrollments' => 0, 'revenue' => 0]
                );

                $analytics->increment('views', $views);
                $analytics->increment('clicks', $clicks);

                // Decrement the values we just processed from Redis
                if ($views > 0) {
                    Redis::decrby("feature_section:{$sectionId}:views", $views);
                }
                if ($clicks > 0) {
                    Redis::decrby("feature_section:{$sectionId}:clicks", $clicks);
                }
            }
        } catch (\Throwable $e) {
            // Redis may be unavailable or disabled; log warning to avoid crashing recurring scheduler
            Log::warning('FlushFeatureSectionAnalyticsJob: Redis operation failed or connection is unavailable, skipping flush.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Use SCAN instead of KEYS to safely iterate over matching Redis keys.
     * KEYS blocks the entire Redis server and can cause timeouts in production.
     * SCAN is cursor-based and non-blocking.
     *
     * @return array<int, string>
     */
    private function scanKeys(string $pattern): array
    {
        $keys = [];
        $cursor = '0';

        do {
            [$cursor, $found] = Redis::scan($cursor, 'MATCH', $pattern, 'COUNT', 100);
            if (is_array($found)) {
                $keys = array_merge($keys, $found);
            }
        } while ($cursor !== '0' && $cursor !== 0);

        return $keys;
    }
}
