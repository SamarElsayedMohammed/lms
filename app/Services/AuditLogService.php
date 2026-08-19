<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'bearer_token',
        'fcm_token',
        'secret',
        'client_secret',
        'authorization',
        'credit_card',
        'card_number',
        'cvv',
        'private_key',
        'api_key',
        'app_key',
        'signature',
    ];

    /**
     * Record an immutable admin audit log entry with automatic secret redaction.
     */
    public static function log(
        string $action,
        ?Model $target = null,
        ?string $summary = null,
        array $details = [],
        ?User $actor = null
    ): AdminAuditLog {
        $user = $actor ?? Auth::guard('sanctum')->user() ?? Auth::user();

        $sanitizedDetails = self::redactSensitiveData($details);

        $targetType = $target ? class_basename($target) : null;
        $targetId = $target?->getKey();

        return AdminAuditLog::create([
            'user_id'     => $user?->id,
            'actor_name'  => $user?->name ?? 'System / Anonymous',
            'actor_email' => $user?->email,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId ? (int) $targetId : null,
            'summary'     => $summary ?? self::generateDefaultSummary($action, $targetType, $targetId),
            'details'     => empty($sanitizedDetails) ? null : $sanitizedDetails,
            'ip_address'  => Request::ip(),
            'user_agent'  => Request::userAgent(),
            'created_at'  => now(),
        ]);
    }

    /**
     * Recursively redact sensitive keys from details payload.
     */
    public static function redactSensitiveData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            $isSensitive = false;
            foreach (self::REDACTED_KEYS as $sensitivePattern) {
                if (str_contains($normalizedKey, $sensitivePattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::redactSensitiveData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private static function generateDefaultSummary(string $action, ?string $targetType, mixed $targetId): string
    {
        $targetDesc = $targetType ? " on {$targetType} #{$targetId}" : '';
        return "Performed action '{$action}'{$targetDesc}";
    }
}
