<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Models\UserFcmToken;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFcmNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 3;

    protected array $registrationIDs;
    protected ?string $title;
    protected ?string $message;
    protected string $type;
    protected array $customBodyFields;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        array $registrationIDs,
        ?string $title = '',
        ?string $message = '',
        string $type = 'default',
        array $customBodyFields = []
    ) {
        $this->registrationIDs = $registrationIDs;
        $this->title = $title;
        $this->message = $message;
        $this->type = $type;
        $this->customBodyFields = $customBodyFields;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $project_id = Setting::select('value')->where('name', 'firebase_project_id')->first();
            if (empty($project_id->value)) {
                Log::error('FCM configurations are not configured.');
                return;
            }

            $project_id = $project_id->value;
            $url = 'https://fcm.googleapis.com/v1/projects/' . $project_id . '/messages:send';

            $access_token = NotificationService::getAccessToken();
            if (isset($access_token['error']) && $access_token['error']) {
                Log::error('FCM Access Token Error: ' . json_encode($access_token));
                return;
            }

            $deviceInfo = UserFcmToken::select(['platform_type', 'fcm_token'])->whereIn(
                'fcm_token',
                $this->registrationIDs,
            )->get();

            $dataWithTitle = [
                ...$this->customBodyFields,
                'title' => $this->title,
                'body' => $this->message,
                'type' => $this->type,
            ];

            foreach ($this->registrationIDs as $registrationID) {
                $platform = $deviceInfo->first(static fn($q) => $q->fcm_token == $registrationID);
                $data = [
                    'message' => [
                        'token' => $registrationID,
                        'data' => NotificationService::convertToStringRecursively($dataWithTitle),
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10',
                            ],
                            'payload' => [
                                'aps' => [
                                    'alert' => [
                                        'title' => $this->title,
                                        'body' => $this->message,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];

                if (strtolower((string) $platform?->platform_type) !== 'android') {
                    $data['message']['notification'] = [
                        'title' => $this->title,
                        'body' => $this->message,
                    ];
                }

                $encodedData = json_encode($data);
                $headers = [
                    'Authorization: Bearer ' . $access_token['data'],
                    'Content-Type: application/json',
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                // Disabling SSL Certificate support temporarly
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedData);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

                $result = curl_exec($ch);
                if ($result === false) {
                    Log::error('Curl failed: ' . curl_error($ch));
                }
                curl_close($ch);
            }
        } catch (\Throwable $th) {
            Log::error('FCM Error: ' . $th->getMessage());
        }
    }
}
