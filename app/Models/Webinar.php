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
        'features',
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
        'max_attendees',
        'tags',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'is_free' => 'boolean',
        'price' => 'decimal:2',
        'features' => 'array',
        'max_attendees' => 'integer',
    ];

    // Accessor for spots left
    public function getSpotsLeftAttribute(): int
    {
        if ($this->max_attendees <= 0) {
            return 9999; // Unlimited
        }
        return max(0, $this->max_attendees - $this->registrations()->count());
    }

    // Accessor for is full
    public function getIsFullAttribute(): bool
    {
        if ($this->max_attendees <= 0) {
            return false;
        }
        return $this->registrations()->count() >= $this->max_attendees;
    }

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
