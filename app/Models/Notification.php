<?php

namespace App\Models;

use App\Services\FileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $table = 'legacy_notifications';

    protected $fillable = [
        'title',
        'message',
        'type',
        'type_id',
        'type_link',
        'image',
        'date_sent',
    ];

    // If you want automatic casting for date_sent
    protected $casts = [
        'date_sent' => 'datetime',
    ];

    // Disable default timestamps since table doesn't have created_at/updated_at
    public $timestamps = false;

    public function getImageAttribute($value)
    {
        if ($value) {
            return FileService::getFileUrl($value);
        }
        return null;
    }
}
