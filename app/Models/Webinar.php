<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Webinar extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'instructor_id',
        'title',
        'slug',
        'description',
        'image',
        'start_at',
        'duration',
        'meeting_id',
        'meeting_password',
        'join_url',
        'recording_url',
        'provider',
        'status',
        'is_free',
        'price',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'is_free' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function registrations()
    {
        return $this->hasMany(WebinarRegistration::class);
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'webinar_registrations');
    }
}
