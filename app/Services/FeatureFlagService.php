<?php

namespace App\Services;

use App\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;

class FeatureFlagService
{
    private const int CACHE_TTL = 3600;

    /**
     * Check if a feature flag is enabled.
     *
     * @param  string  $key  Feature flag key
     * @param  bool  $default  Default value when flag row is missing from DB
     */
    public function isEnabled(string $key, bool $default = true): bool
    {
        $cacheKey = "feature_flag:{$key}";

        return (bool) Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $default) {
            $flag = FeatureFlag::where('key', $key)->first();

            return $flag !== null ? $flag->is_enabled : $default;
        });
    }

    /**
     * Get a feature flag by key.
     */
    public function get(string $key): ?FeatureFlag
    {
        $cacheKey = "feature_flag:{$key}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key) {
            return FeatureFlag::where('key', $key)->first();
        });
    }

    /**
     * Get all feature flags.
     *
     * @return \Illuminate\Support\Collection<int, FeatureFlag>
     */
    public function getAll(): \Illuminate\Support\Collection
    {
        return Cache::remember('feature_flags:all', self::CACHE_TTL, function () {
            return FeatureFlag::orderBy('key')->get();
        });
    }

    /**
     * Clear feature flag cache.
     *
     * @param  string|null  $key  Specific key to clear, or null to clear all
     */
    public function clearCache(?string $key = null): void
    {
        if ($key !== null) {
            Cache::forget("feature_flag:{$key}");
        }
        Cache::forget('feature_flags:all');
    }
}
