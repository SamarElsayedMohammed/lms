<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use App\Models\FeatureSectionAnalyticsDaily;

class FlushFeatureSectionAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $date = now()->format('Y-m-d');

        // Look for keys matching our pattern
        // Example key: feature_section:1:views
        $viewKeys = Redis::keys('feature_section:*:views');
        $clickKeys = Redis::keys('feature_section:*:clicks');

        $interactions = [];

        foreach ($viewKeys as $key) {
            // Redis keys returns keys with prefix, depending on config. Let's parse section ID
            // Assuming pattern feature_section:{id}:views
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
            // We use decrby to avoid losing new increments that occurred during processing
            if ($views > 0) {
                Redis::decrby("feature_section:{$sectionId}:views", $views);
            }
            if ($clicks > 0) {
                Redis::decrby("feature_section:{$sectionId}:clicks", $clicks);
            }
        }
    }
}
