<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'name_en',
        'name_ar',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Scope: Only active countries
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }
}