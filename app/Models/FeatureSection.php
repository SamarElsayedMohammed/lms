<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Course\Course;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeatureSection extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'feature_sections';

    protected $fillable = [
        'type',
        'title',
        'subtitle',
        'limit',
        'row_order',
        'mobile_row_order',
        'is_active',
        'layout',
        'grid_columns',
        'background',
        'sorting',
        'responsive_limits',
        'visibility_permissions',
        'visibility_devices',
        'audience',
        'config',
        'show_on_web',
        'show_on_mobile',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'limit'                  => 'integer',
        'row_order'              => 'integer',
        'mobile_row_order'       => 'integer',
        'grid_columns'           => 'integer',
        'responsive_limits'      => 'array',
        'visibility_permissions' => 'array',
        'visibility_devices'     => 'array',
        'config'                 => 'array',
        'show_on_web'            => 'boolean',
        'show_on_mobile'         => 'boolean',
    ];

    #[\Override]
    public static function boot()
    {
        parent::boot();

        static::creating(static function ($model): void {
            $maxSortOrder = static::max('row_order') ?? 0;
            $model->row_order = $maxSortOrder + 1;
        });
    }

    public function images()
    {
        return $this->hasMany(FeatureSectionImage::class);
    }

    public function manualCourses()
    {
        return $this->belongsToMany(Course::class, 'feature_section_manual_courses')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('feature_section_manual_courses.sort_order');
    }

    public function analyticsDaily()
    {
        return $this->hasMany(FeatureSectionAnalyticsDaily::class);
    }
}
