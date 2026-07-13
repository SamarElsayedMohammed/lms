<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureSectionAnalyticsDaily extends Model
{
    use HasFactory;

    protected $table = 'feature_section_analytics_daily';

    protected $fillable = [
        'feature_section_id',
        'date',
        'views',
        'clicks',
        'enrollments',
        'revenue',
    ];

    protected $casts = [
        'date' => 'date',
        'views' => 'integer',
        'clicks' => 'integer',
        'enrollments' => 'integer',
        'revenue' => 'decimal:2',
    ];

    public function featureSection()
    {
        return $this->belongsTo(FeatureSection::class);
    }
}
