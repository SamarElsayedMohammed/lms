<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Contact Message Model
 *
 * @property int $id
 * @property string $first_name
 * @property string $email
 * @property string $message
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read string $status_label
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ContactMessage new()
 * @method static \Illuminate\Database\Eloquent\Builder|ContactMessage read()
 * @method static \Illuminate\Database\Eloquent\Builder|ContactMessage replied()
 * @method static \Illuminate\Database\Eloquent\Builder|ContactMessage closed()
 * @method static ContactMessage|null find(int|string $id)
 * @method static ContactMessage findOrFail(int|string $id)
 * @method static \Illuminate\Database\Eloquent\Builder|ContactMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder|ContactMessage where(string $column, mixed $value)
 * @method static \Illuminate\Database\Eloquent\Collection|ContactMessage[] get()
 * @method ContactMessage fresh()
 * @method bool update(array $attributes = [])
 * @method bool delete()
 */
class ContactMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'email',
        'message',
        'ip_address',
        'user_agent',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the status options
     */
    public static function getStatusOptions()
    {
        return [
            'new' => 'New',
            'read' => 'Read',
            'replied' => 'Replied',
            'closed' => 'Closed',
        ];
    }

    /**
     * Get the status label
     */
    public function getStatusLabelAttribute()
    {
        $statuses = self::getStatusOptions();
        return $statuses[$this->status] ?? 'Unknown';
    }

    /**
     * Scope for new messages
     */
    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    /**
     * Scope for read messages
     */
    public function scopeRead($query)
    {
        return $query->where('status', 'read');
    }

    /**
     * Scope for replied messages
     */
    public function scopeReplied($query)
    {
        return $query->where('status', 'replied');
    }

    /**
     * Scope for closed messages
     */
    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    /**
     * Mark as read
     */
    public function markAsRead()
    {
        $this->update(['status' => 'read']);
    }

    /**
     * Mark as replied
     */
    public function markAsReplied()
    {
        $this->update(['status' => 'replied']);
    }

    /**
     * Mark as closed
     */
    public function markAsClosed()
    {
        $this->update(['status' => 'closed']);
    }
}
