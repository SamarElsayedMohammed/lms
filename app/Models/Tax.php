<?php

namespace App\Models;

use App\Models\Course\Course;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tax extends Model
{
    use HasFactory;

    use SoftDeletes;

    /**
     * Request-level cache for tax percentages to avoid duplicate queries
     * @var array
     */
    protected static $taxCache = [];

    protected $fillable = [
        'name',
        'percentage',
        'country_code',
        'is_active',
        'is_default',
        'is_inclusive',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_inclusive' => 'boolean',
        'percentage' => 'float',
    ];

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'course_tax');
    }

    /**
     * Get total tax percentage for a given country code
     * Returns sum of all active taxes applicable to the country
     * If country code is not available, returns default tax
     *
     * Uses request-level caching to avoid duplicate queries
     *
     * @param string|null $countryCode ISO 3166-1 alpha-2 country code (e.g., 'US', 'IN', 'GB')
     * @return float
     */
    public static function getTotalTaxPercentageByCountry($countryCode = null)
    {
        // Create a cache key based on country code
        $cacheKey = $countryCode ?? 'default';

        // Return cached value if it exists
        if (isset(static::$taxCache[$cacheKey])) {
            return static::$taxCache[$cacheKey];
        }

        $query = static::where('is_active', 1);

        if ($countryCode) {
            // Get taxes for specific country
            $query->where('country_code', $countryCode);
        } else {
            // If no country code, get default tax (is_default = true)
            $query->where('is_default', true);
        }

        $taxPercentage = (float) $query->sum('percentage');

        // If no country-specific tax found and country code was provided, fallback to default tax
        if ($taxPercentage == 0 && $countryCode) {
            // Check if default tax is already cached
            if (!isset(static::$taxCache['default'])) {
                $defaultTax = static::where('is_active', 1)->where('is_default', true)->sum('percentage');
                static::$taxCache['default'] = (float) $defaultTax;
            }
            return static::$taxCache['default'];
        }

        // Cache the result for this request
        static::$taxCache[$cacheKey] = $taxPercentage;

        return $taxPercentage;
    }
}
