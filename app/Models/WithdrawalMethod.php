<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WithdrawalMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'currency',
        'min_amount',
        'max_amount',
        'fixed_fee',
        'percent_fee',
        'estimated_delay',
        'is_active',
        'description',
        'image',
        'dynamic_fields',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'fixed_fee' => 'decimal:2',
        'percent_fee' => 'decimal:2',
        'dynamic_fields' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->code)) {
                $model->code = Str::slug($model->name) . '-' . uniqid();
            }
        });
    }

    public function getImageAttribute($value)
    {
        if ($value) {
            return \App\Services\FileService::getFileUrl($value);
        }
        return null;
    }
}
