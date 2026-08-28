<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Slider extends Model
{
    use HasFactory, SoftDeletes;

    protected $appends = ['image_url', 'mobile_image_url'];
    protected $fillable = [
        'image',
        'mobile_image',
        'title',
        'subtitle',
        'order',
        'third_party_link',
        'cta_label',
        'cta_type',
        'cta_target',
        'audience',
        'is_active',
        'start_at',
        'end_at',
        'model_type',
        'model_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_at'  => 'datetime',
        'end_at'    => 'datetime',
    ];

    /**
     * Get the owning model.
     */
    public function model()
    {
        return $this->morphTo();
    }

    public function getImageUrlAttribute()
    {
        if (empty($this->image)) {
            return '';
        }
        if (str_starts_with($this->image, 'http://') || str_starts_with($this->image, 'https://')) {
            return $this->image;
        }
        return asset('storage/' . $this->image);
    }

    public function getMobileImageUrlAttribute()
    {
        if (!empty($this->mobile_image)) {
            if (str_starts_with($this->mobile_image, 'http://') || str_starts_with($this->mobile_image, 'https://')) {
                return $this->mobile_image;
            }
            return asset('storage/' . $this->mobile_image);
        }
        return $this->getImageUrlAttribute();
    }

    /**
     * Scope for active banners on mobile respecting scheduling and audience.
     */
    public function scopeActiveForMobile($query, $user = null)
    {
        $now = now();
        return $query->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_at')->orWhere('end_at', '>=', $now);
            });
    }
}
