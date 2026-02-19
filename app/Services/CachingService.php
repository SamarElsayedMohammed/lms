<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class CachingService
{
    /**
     * @param $key
     * @param callable $callback - Callback function must return a value
     * @param int $time = 3600
     * @return mixed
     */
    public static function cacheRemember($key, callable $callback, int $time = 3600)
    {
        return Cache::remember($key, $time, $callback);
    }

    public static function removeCache($key)
    {
        Cache::forget($key);
    }

    /**
     * @param array|string $key
     * @return mixed|string
     */
    public static function getSystemSettings(array|string $key = '*')
    {
        // Check if we're in installer mode or app is not installed
        $isInstallerRoute = request()->is('install*') || request()->is('update*');
        $isInstalled = file_exists(storage_path('installed'));

        // During installation, check if settings table exists
        if ($isInstallerRoute || !$isInstalled) {
            try {
                if (!Schema::hasTable('settings')) {
                    // Return empty collection or empty string based on key type
                    if ($key === '*') {
                        return collect([]);
                    } elseif (is_array($key)) {
                        return array_fill_keys($key, '');
                    } else {
                        return '';
                    }
                }
            } catch (\Exception) {
                // If schema check fails, return empty data
                if ($key === '*') {
                    return collect([]);
                } elseif (is_array($key)) {
                    return array_fill_keys($key, '');
                } else {
                    return '';
                }
            }
        }

        try {
            $settings = self::cacheRemember(config('constants.CACHE.SETTINGS'), static function () {
                // Double-check table exists before querying (safety check)
                if (!Schema::hasTable('settings')) {
                    return collect([]);
                }
                return Setting::all()->pluck('value', 'name');
            });
        } catch (\Exception) {
            // If query fails (table doesn't exist or connection error), return empty data
            if ($key === '*') {
                return collect([]);
            } elseif (is_array($key)) {
                return array_fill_keys($key, '');
            } else {
                return '';
            }
        }

        if ($key != '*') {
            // Handle specific key requests

            // If array is given in Key param
            if (is_array($key)) {
                $specificSettings = [];
                $missingKeys = [];

                // First check which keys we already have in cache
                foreach ($key as $row) {
                    if ($settings && is_object($settings) && $settings->has($row)) {
                        $specificSettings[$row] = $settings[$row];
                    } else {
                        // Mark keys not found in cache
                        $missingKeys[] = $row;
                    }
                }

                // If we have missing keys, fetch them from the database
                if (!empty($missingKeys)) {
                    try {
                        // Check if settings table exists before querying
                        if (!Schema::hasTable('settings')) {
                            // Table doesn't exist, return empty values for missing keys
                            foreach ($missingKeys as $missingKey) {
                                $specificSettings[$missingKey] = '';
                            }
                            return $specificSettings;
                        }
                        $dbSettings = Setting::whereIn('name', $missingKeys)->get()->pluck('value', 'name');
                    } catch (\Exception) {
                        // If query fails, return empty values for missing keys
                        foreach ($missingKeys as $missingKey) {
                            $specificSettings[$missingKey] = '';
                        }
                        return $specificSettings;
                    }

                    // Add the missing keys from DB to our result
                    foreach ($missingKeys as $missingKey) {
                        if ($dbSettings->has($missingKey)) {
                            $specificSettings[$missingKey] = $dbSettings[$missingKey];

                            // Also update the main cache for future requests
                            if (is_object($settings)) {
                                $settings->put($missingKey, $dbSettings[$missingKey]);
                                Cache::put(
                                    config('constants.CACHE.SETTINGS'),
                                    $settings,
                                    config('constants.CACHE.SETTINGS_TTL', 86400),
                                );
                            }
                        } else {
                            // Key doesn't exist in database either
                            $specificSettings[$missingKey] = '';
                        }
                    }
                }

                return $specificSettings;
            }

            // If String is given in Key param
            if ($settings && is_object($settings) && $settings->has($key)) {
                return $settings[$key];
            } else {
                // Key not found in cache, try to get from database
                try {
                    // Check if settings table exists before querying
                    if (!Schema::hasTable('settings')) {
                        return '';
                    }
                    $dbSetting = Setting::where('name', $key)->first();
                } catch (\Exception) {
                    // If query fails, return empty string
                    return '';
                }

                if ($dbSetting) {
                    $value = $dbSetting->value;

                    // Update the cache for future requests
                    if (is_object($settings)) {
                        $settings->put($key, $value);
                        Cache::put(
                            config('constants.CACHE.SETTINGS'),
                            $settings,
                            config('constants.CACHE.SETTINGS_TTL', 86400),
                        );
                    }

                    return $value;
                }
            }

            return '';
        }

        return $settings;
    }

    public static function getLanguages()
    {
        // Check if we're in installer mode or app is not installed
        $isInstallerRoute = request()->is('install*') || request()->is('update*');
        $isInstalled = file_exists(storage_path('installed'));

        // During installation, check if languages table exists
        if ($isInstallerRoute || !$isInstalled) {
            try {
                if (!Schema::hasTable('languages')) {
                    return collect([]);
                }
            } catch (\Exception) {
                return collect([]);
            }
        }

        // Try to get languages from cache or database
        try {
            return self::cacheRemember(config('constants.CACHE.LANGUAGE'), static function () {
                // Double-check table exists before querying (safety check)
                if (!Schema::hasTable('languages')) {
                    return collect([]);
                }
                return Language::all();
            });
        } catch (\Exception) {
            // If query fails (table doesn't exist or connection error), return empty collection
            return collect([]);
        }
    }

    public static function getDefaultLanguage()
    {
        // Check if we're in installer mode or app is not installed
        $isInstallerRoute = request()->is('install*') || request()->is('update*');
        $isInstalled = file_exists(storage_path('installed'));

        // During installation, check if languages table exists
        if ($isInstallerRoute || !$isInstalled) {
            try {
                if (!Schema::hasTable('languages')) {
                    return null;
                }
            } catch (\Exception) {
                return null;
            }
        }

        // Try to get default language from cache or database
        try {
            return self::cacheRemember(config('constants.CACHE.DEFAULT_LANGUAGE'), static function () {
                // Double-check table exists before querying (safety check)
                if (!Schema::hasTable('languages')) {
                    return null;
                }
                return Language::getDefault();
            });
        } catch (\Exception) {
            // If query fails (table doesn't exist or connection error), return null
            return null;
        }
    }
}
