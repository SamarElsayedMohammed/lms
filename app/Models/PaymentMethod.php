<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_active',
        'logo',
        'account_name',
        'account_number',
        'instapay_id',
        'merchant_code',
        'instructions',
        'dynamic_fields',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'dynamic_fields' => 'array',
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name) . '-' . uniqid();
            }
        });
    }

    public function getLogoAttribute($value)
    {
        if ($value) {
            return \App\Services\FileService::getFileUrl($value);
        }
        return null;
    }
}
