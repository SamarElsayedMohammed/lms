<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $actor_name
 * @property string|null $actor_email
 * @property string $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $summary
 * @property array|null $details
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 * @property-read User|null $user
 */
final class AdminAuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'admin_audit_logs';

    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_email',
        'action',
        'target_type',
        'target_id',
        'summary',
        'details',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'details'    => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(static function (): bool {
            return false;
        });
        static::deleting(static function (): bool {
            return false;
        });
    }
}
