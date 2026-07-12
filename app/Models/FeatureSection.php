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
        'limit',
        'row_order',
        'is_active',
        'layout',
        'grid_columns',
        'background',
        'sorting',
        'responsive_limits',
        'visibility_permissions',
        'visibility_devices',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'limit'                  => 'integer',   // was arriving as string from MySQL
        'row_order'              => 'integer',   // was arriving as string from MySQL
        'grid_columns'           => 'integer',
        'responsive_limits'      => 'array',
        'visibility_permissions' => 'array',
        'visibility_devices'     => 'array',
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
}
