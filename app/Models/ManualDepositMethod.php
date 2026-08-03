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
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getImageAttribute($value)
    {
        if ($value) {
            $clean = is_string($value) ? trim($value, " \t\n\r\0\x0B'\"`") : $value;
            return FileService::getFileUrl($clean);
        }
        return null;
    }
}
