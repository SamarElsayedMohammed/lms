<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Slider extends Model
{
    use HasFactory, SoftDeletes;

    protected $appends = ['image_url'];
    protected $fillable = [
        'image',
        'order',
        'third_party_link',
        'model_type',
        'model_id',
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
        return asset('storage/' . $this->image);
    }
}
