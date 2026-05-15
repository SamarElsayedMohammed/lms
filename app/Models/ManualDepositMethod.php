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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'countries' => 'array',
    ];

    public function getImageAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }
}
