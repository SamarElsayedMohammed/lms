<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

class GoogleMeetService
{
    private $client;

    public function __construct()
    {
        $this->client = new \Google\Client();
        $this->client->setApplicationName('LMS Webinar System');
        $this->client->setScopes([\Google\Service\Calendar::CALENDAR_EVENTS]);
        
        $base64Credentials = (string) config('services.google_meet.credentials_base64');
        $credentialsPath = storage_path('app/google-credentials.json');

        if (!empty($base64Credentials)) {
            $credentialsArray = json_decode(base64_decode($base64Credentials), true);
            if (is_array($credentialsArray)) {
                $this->client->setAuthConfig($credentialsArray);
            } else {
                Log::error('Failed to decode GOOGLE_MEET_CREDENTIALS_BASE64');
            }
        } elseif (file_exists($credentialsPath)) {
            $this->client->setAuthConfig($credentialsPath);
        } else {
            Log::warning('Google credentials not found in .env or storage/app/google-credentials.json');
        }
        
        $this->client->setAccessType('offline');
    }

    /**
     * Create a Google Meet event
     *
     * @param string $title
     * @param string $startAt (e.g. '2026-05-10T10:00:00+03:00')
     * @param int $durationMinutes
     * @param string $timezone
     * @return array
     */
    public function createMeeting(string $title, string $startAt, int $durationMinutes, string $timezone = 'UTC'): array
    {
        $base64Credentials = (string) config('services.google_meet.credentials_base64');
        if (empty($base64Credentials) && !file_exists(storage_path('app/google-credentials.json'))) {
            return [
                'success' => false,
                'message' => 'Google credentials are missing. Please set GOOGLE_MEET_CREDENTIALS_BASE64 in .env or provide the json file.',
            ];
        }

        try {
            $service = new \Google\Service\Calendar($this->client);

            $startTime = new \DateTime($startAt);
            $endTime = clone $startTime;
            $endTime->add(new \DateInterval('PT' . $durationMinutes . 'M'));

            $event = new \Google\Service\Calendar\Event([
                'summary' => $title,
                'description' => 'Webinar: ' . $title,
                'start' => [
                    'dateTime' => $startTime->format(\DateTime::RFC3339),
                    'timeZone' => $timezone,
                ],
                'end' => [
                    'dateTime' => $endTime->format(\DateTime::RFC3339),
                    'timeZone' => $timezone,
                ],
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => uniqid('lms_webinar_', true),
                        'conferenceSolutionKey' => [
                            'type' => 'hangoutsMeet'
                        ]
                    ]
                ]
            ]);

            $calendarId = (string) (config('services.google_meet.calendar_id') ?: 'primary');

            $event = $service->events->insert($calendarId, $event, ['conferenceDataVersion' => 1]);

            if ($event->conferenceData && $event->conferenceData->entryPoints) {
                $meetLink = null;
                foreach ($event->conferenceData->entryPoints as $entryPoint) {
                    if ($entryPoint->entryPointType === 'video') {
                        $meetLink = $entryPoint->uri;
                        break;
                    }
                }

                if ($meetLink) {
                    return [
                        'success' => true,
                        'data' => [
                            'join_url' => $meetLink,
                            'meeting_id' => $event->id, // Calendar event ID
                            'password' => '', // Google Meet doesn't use passwords in the same way Zoom does
                        ]
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Event created but Google Meet link was not generated.',
            ];

        } catch (\Exception $e) {
            Log::error('Google Meet Service Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error communicating with Google API: ' . $e->getMessage(),
            ];
        }
    }
}
