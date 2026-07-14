<?php

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Model;

class ManualDepositMethod extends Model
{
    protected $fillable = [
        'name',
        'image',
        'account_details',
        'instructions',
        'countries',
        'is_active',
        'currency',
        'min_amount',
        'max_amount',
        'fixed_fee',
        'percent_fee',
        'dynamic_fields',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'countries' => 'array',
        'dynamic_fields' => 'array',
        'min_amount' => 'float',
        'max_amount' => 'float',
        'fixed_fee' => 'float',
        'percent_fee' => 'float',
    ];

    public function getImageAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }
}
