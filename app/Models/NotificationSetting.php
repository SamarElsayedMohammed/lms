<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $fillable = [
        'user_id',
        'setting_key',
        'email_enabled',
        'push_enabled'
    ];
}
