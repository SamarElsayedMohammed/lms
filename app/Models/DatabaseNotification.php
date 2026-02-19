<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification as LaravelDatabaseNotification;

class DatabaseNotification extends LaravelDatabaseNotification
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'notifications';

    /**
     * Get the notifiable entity that the notification belongs to.
     */
    #[\Override]
    public function notifiable()
    {
        return $this->morphTo();
    }

    /**
     * Scope to filter by notification type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get unread notifications
     */
    #[\Override]
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope to get read notifications
     */
    #[\Override]
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Get notification data as array
     */
    public function getDataAttribute($value)
    {
        return json_decode((string) $value, true);
    }

    /**
     * Set notification data
     */
    public function setDataAttribute($value)
    {
        $this->attributes['data'] = is_string($value) ? $value : json_encode($value);
    }
}
