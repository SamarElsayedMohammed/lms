<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
