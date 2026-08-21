<?php

namespace App\Jobs;

use App\Helpers\FirebaseHelper;
use App\Models\UserFcmToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFcmNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const TOKEN_CHUNK_SIZE = 50;

    public int $timeout = 120;
    public int $tries = 3;
    public int|array $backoff = [10, 30, 60];

    protected array $registrationIDs;
    protected ?string $title;
    protected ?string $message;
    protected string $type;
    protected array $customBodyFields;

    /**
     * @param array<int, string> $registrationIDs
     * @param array<string, mixed> $customBodyFields
     */
    public function __construct(
        array $registrationIDs,
        ?string $title = '',
        ?string $message = '',
        string $type = 'default',
        array $customBodyFields = []
    ) {
        $this->registrationIDs = array_values(array_unique(array_filter($registrationIDs)));
        $this->title = $title;
        $this->message = $message;
        $this->type = $type;
        $this->customBodyFields = $customBodyFields;
    }

    public function handle()
    {
        if ($this->registrationIDs === []) {
            return;
        }

        $tokenChunks = array_chunk($this->registrationIDs, self::TOKEN_CHUNK_SIZE);
        if (count($this->registrationIDs) > self::TOKEN_CHUNK_SIZE) {
            foreach ($tokenChunks as $tokenChunk) {
                static::dispatch(
                    $tokenChunk,
                    $this->title,
                    $this->message,
                    $this->type,
                    $this->customBodyFields,
                );
            }

            return;
        }

        try {
            $deviceInfo = UserFcmToken::query()
                ->select(['platform_type', 'fcm_token'])
                ->whereIn('fcm_token', $this->registrationIDs)
                ->get()
                ->keyBy('fcm_token');

            $fcmData = FirebaseHelper::stringifyData([
                ...$this->customBodyFields,
                'title' => $this->title,
                'body' => $this->message,
                'type' => $this->type,
            ]);

            foreach ($this->registrationIDs as $registrationID) {
                $platform = strtolower((string) ($deviceInfo->get($registrationID)?->platform_type ?: 'web'));
                if (!in_array($platform, ['ios', 'android', 'web'], true)) {
                    $platform = 'web';
                }

                try {
                    FirebaseHelper::send($platform, $registrationID, $fcmData, [
                        'title' => $this->title,
                        'body' => $this->message,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('FCM send failed for one token', [
                        'token_prefix' => substr((string) $registrationID, 0, 12),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $th) {
            Log::error('FCM job error: ' . $th->getMessage());
        }
    }
}
