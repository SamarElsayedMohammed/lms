<?php

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureSectionImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'feature_section_id',
        'image',
    ];

    public function getImageAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }

    public function featureSection()
    {
        return $this->belongsTo(FeatureSection::class);
    }
}
