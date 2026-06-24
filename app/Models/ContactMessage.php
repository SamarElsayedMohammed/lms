<?php

namespace App\Models;

use App\Models\User;
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
        'user_id',
        'first_name',
        'email',
        'message',
        'reply_message',
        'ip_address',
        'user_agent',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The registered user who sent this message (nullable for guests).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this message was sent by a registered user.
     */
    public function hasSenderAccount(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Get the status options
     */
    public static function getStatusOptions()
    {
        return [
            'new' => 'New',
            'read' => 'Read',
            'waiting_admin' => 'Waiting Admin',
            'replied' => 'Replied',
            'closed' => 'Closed',
            'completed' => 'Completed',
            'reopened' => 'Reopened',
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
     * Scope for read messages (admin viewed, not yet replied)
     */
    public function scopeRead($query)
    {
        return $query->where('status', 'read');
    }

    /**
     * Scope for waiting_admin messages
     */
    public function scopeWaitingAdmin($query)
    {
        return $query->where('status', 'waiting_admin');
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
     * Mark as read (admin viewed, awaiting reply)
     */
    public function markAsRead(): void
    {
        if ($this->status === 'new') {
            $this->update(['status' => 'read']);
        }
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

    /**
     * Get the replies for the message.
     */
    public function replies()
    {
        return $this->hasMany(ContactMessageReply::class);
    }
}
