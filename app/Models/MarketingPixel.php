<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingPixel extends Model
{
    protected $fillable = [
        'platform',
        'pixel_id',
        'is_active',
        'additional_config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'additional_config' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
