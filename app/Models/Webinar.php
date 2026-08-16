<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Course\Course;

class Webinar extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'instructor_id',
        'course_id',
        'title',
        'slug',
        'description',
        'features',
        'config',
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
        'is_published',
        'is_featured',
        'reminder_sent_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'is_free' => 'boolean',
        'price' => 'decimal:2',
        'features' => 'array',
        'config' => 'array',
        'max_attendees' => 'integer',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
    ];

    /**
     * Enforce Route Model Binding by slug instead of ID.
     */
    public function getRouteKeyName()
    {
        return 'slug';
    }

    /**
     * Ensure start_at is always treated and saved as UTC.
     */
    public function setStartAtAttribute($value)
    {
        if ($value) {
            $this->attributes['start_at'] = \Carbon\Carbon::parse($value)->utc();
        }
    }

    /**
     * Serialize dates to standard ISO 8601 UTC format to prevent double-shifting in API responses.
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Count active capacity-consuming registrations (confirmed free/paid + unexpired pending).
     */
    public function activeRegistrationsCount(): int
    {
        return $this->registrations()
            ->where(function ($q) {
                $q->whereIn('payment_status', ['paid', 'free'])
                  ->orWhere(function ($sub) {
                      $sub->where('payment_status', 'pending')
                          ->where(function ($exp) {
                              $exp->whereNull('expires_at')
                                  ->orWhere('expires_at', '>', now());
                          });
                  });
            })->count();
    }

    // Accessor for spots left
    public function getSpotsLeftAttribute(): int
    {
        if ($this->max_attendees <= 0) {
            return 9999; // Unlimited
        }
        return max(0, $this->max_attendees - $this->activeRegistrationsCount());
    }

    // Accessor for is full
    public function getIsFullAttribute(): bool
    {
        if ($this->max_attendees <= 0) {
            return false;
        }
        return $this->activeRegistrationsCount() >= $this->max_attendees;
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
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
